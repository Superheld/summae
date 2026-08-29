<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Summae\Core\DomainError;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Records\Voucher;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Correcting a deducted input tax when the use of the thing it was deducted for has changed
 * (F-CORE-056).
 *
 * **Where the boundary actually runs, because this one was argued twice.** The *register* — which
 * assets are under observation, and until when — stays with the embedding application, and for a
 * reason that survives inspection: the trigger is a **change of use**, which is never posted. A
 * library that sees only postings cannot see the day a van starts being driven privately. What does
 * not stay outside is the arithmetic. The argument that put it there was *"a figure produced wrongly
 * would look exactly as authoritative as one produced rightly"* — which is a reason to compute it
 * where figures are fixture-pinned, deterministic and verified across two languages, not a reason to
 * compute it nowhere.
 *
 * **It is an expansion, not a projection, and that was the second correction.** A projection reads
 * the journal; this reads none of it — every input comes from outside. As an expansion it fits
 * exactly, and it is the better design for an unrelated reason: the correction gets **booked**
 * rather than computed and handed back for somebody else to book.
 *
 * **Socket and plug, with an unusually clean seam.** The mechanism is a pro-rata correction over an
 * observation period with de-minimis thresholds — the same shape wherever such a rule exists. Every
 * number is the pack's: how many years the period runs for which kind of thing, the two thresholds,
 * the accounts, and the key the correction is reported under. The `us` pack simply declares no such
 * module, and `adjustInputTax` then says so instead of inventing a period.
 */
final class InputTaxAdjustmentService
{
    /** @param array<string, mixed> $ruleModule the resolved bundle; `inputTaxAdjustment` is read here */
    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AccountRepository $accounts,
        private readonly VoucherRepository $vouchers,
        private readonly Ledger $ledger,
        private readonly IdGenerator $ids,
        private array $ruleModule = [],
        private readonly ?AuditWriter $audit = null,
    ) {
    }

    /** @param array<string, mixed> $ruleModule */
    public function setRuleModule(array $ruleModule): void
    {
        $this->ruleModule = $ruleModule;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function adjust(array $input): array
    {
        $module = $this->packModule();
        $reason = $this->requireString($input['reason'] ?? null, 'reason');
        $date = $this->requireDate($input['date'] ?? null, 'date');
        $originalInputTax = $this->requireMoney($input['originalInputTax'] ?? null, 'originalInputTax');
        $originalShare = $this->requirePercent($input['originalSharePercent'] ?? null, 'originalSharePercent');
        $currentShare = $this->requirePercent($input['currentSharePercent'] ?? null, 'currentSharePercent');
        $assetKind = $this->requireString($input['assetKind'] ?? null, 'assetKind');
        $years = $this->correctionYears($module, $assetKind);

        if (!$originalInputTax->isPositive()) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'adjustInputTax: originalInputTax must be positive — there is nothing to correct',
                ['field' => 'originalInputTax'],
            );
        }

        $delta = $currentShare->minus($originalShare);

        // Both thresholds, in the order the pack states them. The first is about the thing (too
        // small to observe at all), the second about the change (too small to be worth correcting).
        // Reported as `notDue` with the threshold named, never as a silent zero: "no correction is
        // due" and "we did not compute one" are different answers and only one of them is useful.
        $deMinimis = is_array($module['deMinimis'] ?? null) ? $module['deMinimis'] : [];

        $inputTaxAtMost = $this->optionalMoney($deMinimis['inputTaxAtMost'] ?? null);
        if ($inputTaxAtMost !== null && $originalInputTax->compareTo($inputTaxAtMost) <= 0) {
            return $this->notDue('inputTaxBelowThreshold', $originalInputTax, $delta, $years, $inputTaxAtMost->amountAsString());
        }

        $amount = $this->yearlyCorrection($originalInputTax, $delta, $years);

        $sharePointsAtLeast = $this->optionalPercent($deMinimis['sharePointsAtLeast'] ?? null);
        $amountAtMost = $this->optionalMoney($deMinimis['amountAtMost'] ?? null);
        if (
            $sharePointsAtLeast !== null
            && $amountAtMost !== null
            && $delta->abs()->isLessThan($sharePointsAtLeast)
            && $amount->abs()->compareTo($amountAtMost) <= 0
        ) {
            return $this->notDue('changeBelowThreshold', $originalInputTax, $delta, $years, $sharePointsAtLeast . '%');
        }

        if ($amount->isZero()) {
            return $this->notDue('noChange', $originalInputTax, $delta, $years, null);
        }

        $accounts = $this->accountsFrom($module);
        $reportingKey = is_string($module['reportingKey'] ?? null) ? $module['reportingKey'] : null;

        // A positive delta means more of the thing is now used in a way that allows the deduction,
        // so more tax may be deducted; a negative one means part of what was deducted has to go
        // back. The tax line carries the pack's reporting key so the correction reaches the return
        // where the jurisdiction expects it — without a tag it would balance, sit correctly on the
        // account, and contribute nothing to what is filed.
        $magnitude = $amount->abs();
        $taxTag = $reportingKey === null ? null : ['reportingKey' => $reportingKey];
        $lines = $amount->isPositive()
            ? [
                ['account' => $accounts['taxAccount'], 'side' => 'debit', 'money' => $magnitude->jsonSerialize(), 'taxTag' => $taxTag],
                ['account' => $accounts['incomeAccount'], 'side' => 'credit', 'money' => $magnitude->jsonSerialize()],
            ]
            : [
                ['account' => $accounts['expenseAccount'], 'side' => 'debit', 'money' => $magnitude->jsonSerialize()],
                ['account' => $accounts['taxAccount'], 'side' => 'credit', 'money' => $magnitude->jsonSerialize(), 'taxTag' => $taxTag],
            ];

        $entryId = $this->post($date, sprintf('Input tax adjustment: %s', $reason), $lines);

        $this->audit?->record($this->audit->actorOf($input), 'inputTaxAdjustment', $entryId, 'adjusted', [
            'reason' => ['from' => null, 'to' => $reason],
            'amount' => ['from' => null, 'to' => $amount->amountAsString()],
            'sharePoints' => ['from' => (string) $originalShare, 'to' => (string) $currentShare],
        ]);

        return [
            'due' => true,
            'amount' => $amount->jsonSerialize(),
            'correctionYears' => $years,
            'sharePointsChanged' => self::percentPoints($delta),
            'reportingKey' => $reportingKey,
            'entryId' => $entryId->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notDue(string $reason, Money $originalInputTax, BigDecimal $delta, int $years, ?string $threshold): array
    {
        return [
            'due' => false,
            'notDueBecause' => $reason,
            'threshold' => $threshold,
            'amount' => Money::zero($this->baseCurrency)->jsonSerialize(),
            'correctionYears' => $years,
            'sharePointsChanged' => self::percentPoints($delta),
            'originalInputTax' => $originalInputTax->jsonSerialize(),
            'entryId' => null,
        ];
    }

    /**
     * Percentage points at two decimals, in both languages.
     *
     * `BigDecimal` keeps the scale of its input and `Big` does not, so `100.00 − 60.00` prints
     * `-40.00` on one side and `-40` on the other. The computation uses the full precision that was
     * supplied; only the reported string is normalised.
     */
    private static function percentPoints(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * `originalInputTax × delta% ÷ years`, with one rounding at the end.
     *
     * Scale 20 on the division, because that is big.js's default division precision on the other
     * side: rounding twice at the same two scales in both languages is what makes the last cent
     * equal.
     */
    private function yearlyCorrection(Money $originalInputTax, BigDecimal $delta, int $years): Money
    {
        $divisor = BigDecimal::of(100)->multipliedBy($years);

        return Money::fromCalculation(
            BigDecimal::of($originalInputTax->amountAsString())
                ->multipliedBy($delta)
                ->dividedBy($divisor, 20, RoundingMode::HalfUp),
            $this->baseCurrency,
        );
    }

    /**
     * @param array<string, mixed> $module
     *
     * @return array{taxAccount: string, expenseAccount: string, incomeAccount: string}
     */
    private function accountsFrom(array $module): array
    {
        $declared = is_array($module['accounts'] ?? null) ? $module['accounts'] : [];
        $out = [];

        foreach (['taxAccount', 'expenseAccount', 'incomeAccount'] as $key) {
            $value = $declared[$key] ?? null;
            if (!is_string($value) || $value === '') {
                throw new DomainError('E_PACK_INCOHERENT', sprintf(
                    'the pack declares no %s for the input-tax adjustment',
                    $key,
                ), ['field' => 'inputTaxAdjustment.accounts.' . $key]);
            }
            if ($this->accounts->byNumber(AccountNumber::of($value)) === null) {
                throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf(
                    'the input-tax adjustment names account %s, which does not exist',
                    $value,
                ), ['account' => $value]);
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @param array<string, mixed> $module */
    private function correctionYears(array $module, string $assetKind): int
    {
        foreach (is_array($module['correctionPeriods'] ?? null) ? $module['correctionPeriods'] : [] as $period) {
            if (!is_array($period) || ($period['assetKind'] ?? null) !== $assetKind) {
                continue;
            }
            $years = $period['years'] ?? null;
            if (is_int($years) && $years > 0) {
                return $years;
            }
        }

        // Refused rather than defaulted. An observation period is the whole arithmetic: guessing
        // five where the pack means ten halves every correction, and the figure would look exactly
        // as authoritative as a right one.
        throw new DomainError('E_PACK_INCOHERENT', sprintf(
            'the pack declares no correction period for asset kind "%s"',
            $assetKind,
        ), ['assetKind' => $assetKind]);
    }

    /** @return array<string, mixed> */
    private function packModule(): array
    {
        /** @var array<string, mixed>|null $module */
        $module = is_array($this->ruleModule['inputTaxAdjustment'] ?? null)
            ? $this->ruleModule['inputTaxAdjustment']
            : null;

        return $module ?? throw new DomainError(
            'E_PACK_INCOHERENT',
            'this pack has no input-tax adjustment — it declares no correction periods',
            ['field' => 'inputTaxAdjustment'],
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function post(CalendarDate $date, string $text, array $lines): Uuid
    {
        $voucher = new Voucher(
            $this->ids->next(),
            sprintf('VST-KORR-%s', str_replace('-', '', $date->iso)),
            $date,
            kind: 'internal',
        );
        $this->vouchers->add($voucher);

        $result = $this->ledger->post([
            'entryDate' => $date->iso,
            'voucherId' => $voucher->id->value,
            'text' => $text,
            'lines' => $lines,
        ]);
        $this->ledger->finalize(['entryId' => $result->entry->id->value]);

        return $result->entry->id;
    }

    private function requireString(mixed $raw, string $field): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s is required', $field), ['field' => $field]);
        }

        return $raw;
    }

    private function requireDate(mixed $raw, string $field): CalendarDate
    {
        $value = $this->requireString($raw, $field);

        try {
            return CalendarDate::of($value);
        } catch (InvalidValue) {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s is not a calendar date', $field), ['field' => $field]);
        }
    }

    private function requireMoney(mixed $raw, string $field): Money
    {
        if (!is_array($raw) || !is_string($raw['amount'] ?? null)) {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s must be a money object', $field), ['field' => $field]);
        }

        $currency = is_string($raw['currency'] ?? null) ? $raw['currency'] : $this->baseCurrency->code;

        try {
            return Money::of($raw['amount'], $currency);
        } catch (InvalidValue) {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s is not a valid amount', $field), ['field' => $field]);
        }
    }

    private function optionalMoney(mixed $raw): ?Money
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Money::of($raw, $this->baseCurrency);
        } catch (InvalidValue) {
            return null;
        }
    }

    private function requirePercent(mixed $raw, string $field): BigDecimal
    {
        $value = $this->optionalPercent($raw);

        return $value ?? throw new DomainError('E_INPUT_INVALID', sprintf(
            '%s must be a percentage as a decimal string',
            $field,
        ), ['field' => $field]);
    }

    private function optionalPercent(mixed $raw): ?BigDecimal
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return BigDecimal::of($raw);
        } catch (MathException) {
            return null;
        }
    }
}
