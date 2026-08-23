<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Records\OpenItem;
use Summae\Core\Substrate\Side;
use Summae\Core\Policies\Expansion\Tax\TaxMechanisms;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\SettlementCause;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;

/**
 * VAT return reporting keys via taxTags (SF-09).
 *
 * - Accrual taxation: the posting date counts.
 * - Cash taxation: the VAT return follows the open-item settlements (settledAt);
 *   partial payment proportional (half-up), final payment gets the remainder —
 *   Σ shares = total tax, exact (determinismus.md v0.3).
 *   Tagged postings without their own open item (cash sale, deemed supplies,
 *   prepayments, differences) count directly by the posting date.
 * - Presentation: tax bases per reporting key rounded DOWN to full euros
 *   (reporting-key sum), tax to the cent (api.md v0.3).
 */
final readonly class VatReturnProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private JournalRepository $journal,
        private OpenItemRepository $openItems,
        private VoucherRepository $vouchers,
        private AccountRepository $accounts,
        private TaxCodeRegistry $registry,
        private TaxProfile $profile,
    ) {
    }

    /**
     * @param array<string, mixed> $params year, quarter, asOf?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $year = Parameters::integerOr($params['year'] ?? null, 0);
        $quarter = Parameters::integerOr($params['quarter'] ?? null, 0);
        $month = Parameters::integerOr($params['month'] ?? null, 0);

        // Both would describe two different windows, and picking one silently is how a return gets
        // filed for the wrong period. Absent is still "the whole year".
        if ($quarter !== 0 && $month !== 0) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'vatReturn: give either "quarter" or "month", not both',
                ['quarter' => $quarter, 'month' => $month],
            );
        }

        if ($month !== 0 && ($month < 1 || $month > 12)) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'vatReturn: "month" must be between 1 and 12',
                ['month' => DomainError::rejectedValue($params['month'] ?? null)],
            );
        }
        $asOf = is_string($params['asOf'] ?? null) ? CalendarDate::of($params['asOf']) : null;

        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, array{base: Money, tax: Money}> $keys */
        $keys = [];
        $directions = $this->registryDirections();

        $add = function (string $key, Money $base, Money $tax) use (&$keys, $zero): void {
            /** @var array<string, array{base: Money, tax: Money}> $keys */
            $keys[$key] ??= ['base' => $zero, 'tax' => $zero];
            $keys[$key]['base'] = $keys[$key]['base']->add($base);
            $keys[$key]['tax'] = $keys[$key]['tax']->add($tax);
        };

        if ($this->profile->isCashBasis()) {
            // Settlements: proportional per payment, final remainder exact.
            foreach ($this->openItems->all() as $item) {
                $origin = $this->journal->byId($item->originEntryId);
                if ($origin === null || ($asOf !== null && $origin->entryDate->isAfter($asOf))) {
                    continue;
                }

                $contributions = $this->entryContributions($origin, $directions);
                if ($contributions === []) {
                    continue;
                }

                foreach ($this->allocateToSettlements($item, $contributions) as $share) {
                    if ($asOf !== null && $share['settledAt']->isAfter($asOf)) {
                        continue;
                    }

                    if (self::inPeriod($share['settledAt'], $year, $quarter, $month)) {
                        $add($share['key'], $share['base'], $share['tax']);
                    }
                }
            }

            // Tagged postings without their own open item count directly.
            foreach ($this->journal->all() as $entry) {
                if (!self::inPeriod($entry->entryDate, $year, $quarter, $month)) {
                    continue;
                }

                if ($asOf !== null && $entry->entryDate->isAfter($asOf)) {
                    continue;
                }

                if ($this->openItems->byOriginEntry($entry->id) !== []) {
                    continue;
                }

                // IMPL-005: this loop's premise is "no open item => the money moved at posting
                // time" (a cash sale). A reversal has no open item of its own, but it is not a
                // cash movement either. When the entry it reverses carries open items, its tax
                // already follows those items' settlements above — counting it here would
                // declare a correction for money that never moved: reversing an unpaid invoice
                // would claim back tax that was never due. Reversals of genuinely cash-effective
                // entries (target without open items) still count here, at their own date.
                if ($entry->reverses !== null && $this->openItems->byOriginEntry($entry->reverses) !== []) {
                    continue;
                }

                foreach ($this->entryContributions($entry, $directions) as $key => $contribution) {
                    $add((string) $key, $contribution['base'], $contribution['tax']);
                }
            }
        } else {
            foreach ($this->journal->all() as $entry) {
                // v0.4: accrual assignment follows the supply date (fallback voucher date).
                // SPEC-011: exception reversal/tax correction. A reversing
                // posting inherits the original's voucher (reverse() copies voucherId)
                // and thus its supply date — but belongs in the VAT-return period
                // in which the correction is posted, not
                // retroactively in the original period. Hence: by its own posting date.
                if ($entry->reverses !== null) {
                    $taxDate = $entry->entryDate;
                } else {
                    $voucher = $this->vouchers->byId($entry->voucherId);
                    $taxDate = $voucher === null ? $entry->entryDate : $voucher->taxDate();
                }

                if (!self::inPeriod($taxDate, $year, $quarter, $month)) {
                    continue;
                }

                if ($asOf !== null && $entry->entryDate->isAfter($asOf)) {
                    continue;
                }

                foreach ($this->entryContributions($entry, $directions) as $key => $contribution) {
                    $add((string) $key, $contribution['base'], $contribution['tax']);
                }
            }
        }

        ksort($keys, SORT_STRING);

        $result = [];
        $payload = $zero;

        // Touched reporting keys appear even at 0.00 (neutralization
        // visible, tax-correction cases); never-touched ones are absent.
        foreach ($keys as $key => $amounts) {
            // Official VAT-return convention: round base down to full euros (reporting-key sum).
            $flooredBase = Money::fromCalculation(
                BigDecimal::of($amounts['base']->amountAsString())->toScale(0, RoundingMode::Down),
                $this->baseCurrency,
            );

            $result[(string) $key] = [
                'base' => $flooredBase->amountAsString(),
                'tax' => $amounts['tax']->amountAsString(),
            ];

            $direction = $directions[(string) $key] ?? 'output';
            $payload = $direction === 'input'
                ? $payload->subtract($amounts['tax'])
                : $payload->add($amounts['tax']);
        }

        return [
            'keys' => $result,
            'payload' => $payload->jsonSerialize(),
            'gapWarnings' => $this->gapWarnings($year, $quarter, $month, $asOf),
        ];
    }

    /**
     * Postings that touch a tax account without a tax code (F-TAX-013).
     *
     * The return is built from tax-*coded* postings — `taxTag` is what carries the reporting key and
     * the base. That is a defensible design and it is silent: posting expense / input tax / bank by
     * hand balances, satisfies every invariant, and shows correct figures on the accounts and in the
     * trial balance. Only vatReturn reports zero, because nothing told it which key the amount
     * belongs to. The books look right everywhere except the one place that decides what is filed,
     * and an application's seed script fell into it on the first attempt.
     *
     * So the warning lives here, at the figures, rather than in a projection of its own that whoever
     * files the return may not open. It is **not** a refusal: correction postings legitimately touch
     * these accounts, and a library that blocked them would be wrong more often than the caller.
     *
     * Which accounts count is the pack's answer, not this code's: `tax_in` and `tax_out` are
     * subtypes the chart assigns, so a jurisdiction without input-tax deduction simply has no
     * `tax_in` account and produces no such warning.
     *
     * The window is the posting's tax date in both taxation methods. An untagged line has nothing to
     * attach it to a settlement, so the cash-basis question "when did the money move" has no answer
     * for it — which is part of what makes it worth reporting.
     *
     * @return list<array<string, mixed>>
     */
    private function gapWarnings(int $year, int $quarter, int $month, ?CalendarDate $asOf): array
    {
        $warnings = [];

        foreach ($this->journal->all() as $entry) {
            $voucher = $this->vouchers->byId($entry->voucherId);
            $taxDate = $entry->reverses !== null || $voucher === null ? $entry->entryDate : $voucher->taxDate();
            if (!self::inPeriod($taxDate, $year, $quarter, $month)) {
                continue;
            }
            if ($asOf !== null && $entry->entryDate->isAfter($asOf)) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                if ($line->taxTag !== null) {
                    continue;
                }
                $account = $this->accounts->byId($line->accountId);
                $subtype = $account?->subtype;
                if ($subtype !== 'tax_in' && $subtype !== 'tax_out') {
                    continue;
                }

                $warnings[] = [
                    'reason' => 'tax_account_without_tax_code',
                    'sequenceNumber' => $entry->sequenceNumber,
                    'entryDate' => $entry->entryDate->iso,
                    'account' => $account->number->value,
                    'side' => $line->side->value,
                    'money' => $line->money->jsonSerialize(),
                ];
            }
        }

        // Journal order, then account: the order the postings happened in is the order somebody
        // checking them will work through.
        usort($warnings, static function (array $a, array $b): int {
            $bySeq = $a['sequenceNumber'] <=> $b['sequenceNumber'];

            return $bySeq !== 0 ? $bySeq : strcmp((string) $a['account'], (string) $b['account']);
        });

        return $warnings;
    }

    /**
     * Reporting key -> direction from the rule module (tax account subtype).
     *
     * @return array<string, string> reportingKey -> 'output'|'input'
     */
    private function registryDirections(): array
    {
        $directions = [];

        foreach ($this->registry->allVersions() as $version) {
            if ($version->reportingKey !== null) {
                $directions[$version->reportingKey] = $this->accountDirection($version->taxAccount);
            }

            if ($version->inputReportingKey !== null) {
                $directions[$version->inputReportingKey] = 'input';
            }

            if ($version->baseReportingKey !== null) {
                // Base reporting key follows the supply direction of the main position.
                $directions[$version->baseReportingKey] = TaxMechanisms::mechanismFor($version->mechanism)->vatReturnDirection()
                    ?? $this->accountDirection($version->taxAccount);
            }
        }

        return $directions;
    }

    private function accountDirection(string $accountNumber): string
    {
        if ($accountNumber === '') {
            return 'output'; // tax-exempt reporting keys (intra-community supply) without a tax account
        }

        $account = $this->accounts->byNumber(AccountNumber::of($accountNumber));

        return $account?->subtype === 'tax_in' ? 'input' : 'output';
    }

    /**
     * Contributions of a posting per reporting key. Tax lines provide the tax
     * (sign-correct by side) and the tax base from the
     * taxTag.baseMoney (signed — corrections carry a negative base,
     * e.g. cash discount/bad debt). Only when NO tax line
     * provides a base (e.g. base reporting key under reverse charge),
     * the base comes from the tagged non-tax lines.
     *
     * @param array<string, string> $directions
     *
     * @return array<string, array{base: Money, tax: Money}>
     */
    private function entryContributions(JournalEntry $entry, array $directions): array
    {
        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, array{baseFromTax: Money, hasTaxBase: bool, baseFallback: Money, tax: Money}> $collected */
        $collected = [];

        foreach ($entry->lines() as $line) {
            $tag = $line->taxTag;
            if ($tag === null) {
                continue;
            }

            $key = $tag['reportingKey'] ?? null;
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $key = (string) $key;
            $account = $this->accounts->byId($line->accountId);
            $subtype = $account?->subtype;
            $collected[$key] ??= [
                'baseFromTax' => $zero,
                'hasTaxBase' => false,
                'baseFallback' => $zero,
                'tax' => $zero,
            ];

            if ($subtype === 'tax_out' || $subtype === 'tax_in') {
                $positiveSide = $subtype === 'tax_out' ? Side::Credit : Side::Debit;
                $signed = $line->side === $positiveSide ? $line->money : $line->money->negate();
                $collected[$key]['tax'] = $collected[$key]['tax']->add($signed);

                $baseMoney = $this->tagBaseMoney($tag);
                if ($baseMoney !== null) {
                    // Reversal: a negated tax line also negates the base.
                    if ($line->money->isNegative()) {
                        $baseMoney = $baseMoney->negate();
                    }

                    $collected[$key]['baseFromTax'] = $collected[$key]['baseFromTax']->add($baseMoney);
                    $collected[$key]['hasTaxBase'] = true;
                }
            } else {
                $direction = $directions[$key] ?? 'output';
                $positiveSide = $direction === 'input' ? Side::Debit : Side::Credit;
                $signed = $line->side === $positiveSide ? $line->money : $line->money->negate();
                $collected[$key]['baseFallback'] = $collected[$key]['baseFallback']->add($signed);
            }
        }

        $contributions = [];
        foreach ($collected as $key => $parts) {
            $base = $parts['hasTaxBase'] ? $parts['baseFromTax'] : $parts['baseFallback'];

            if ($base->isZero() && $parts['tax']->isZero()) {
                continue;
            }

            $contributions[(string) $key] = ['base' => $base, 'tax' => $parts['tax']];
        }

        return $contributions;
    }

    /**
     * @param array<string, mixed> $tag
     */
    private function tagBaseMoney(array $tag): ?Money
    {
        $baseMoney = $tag['baseMoney'] ?? null;
        $amount = is_array($baseMoney) && is_string($baseMoney['amount'] ?? null) ? $baseMoney['amount'] : null;

        return $amount === null ? null : Money::of($amount, $this->baseCurrency);
    }

    /**
     * Distributes the reporting-key amounts of an open-item origin across its
     * settlements: proportional half-up, the final payment gets the remainder.
     *
     * @param array<string, array{base: Money, tax: Money}> $contributions
     *
     * @return list<array{key: string, base: Money, tax: Money, settledAt: CalendarDate}>
     */
    private function allocateToSettlements(OpenItem $item, array $contributions): array
    {
        $shares = [];
        /** @var array<string, array{base: Money, tax: Money}> $allocated */
        $allocated = [];
        $remaining = $item->money;
        $total = BigDecimal::of($item->money->amountAsString());

        foreach ($item->settlements() as $settlement) {
            // IMPL-008: a cancellation closes the item without any money moving. Counting it here
            // would declare cash-basis VAT for a reversed invoice that was never paid — the exact
            // opposite of what the reversal means. Skipped before `remaining` is touched, so the
            // proportional split of any real payments is unaffected.
            if ($settlement->cause === SettlementCause::Cancellation) {
                continue;
            }

            $remaining = $remaining->subtract($settlement->money);
            $isFinal = $remaining->isZero();
            $ratio = BigDecimal::of($settlement->money->amountAsString());

            foreach ($contributions as $key => $contribution) {
                $allocated[$key] ??= [
                    'base' => Money::zero($this->baseCurrency),
                    'tax' => Money::zero($this->baseCurrency),
                ];

                if ($isFinal) {
                    $base = $contribution['base']->subtract($allocated[$key]['base']);
                    $tax = $contribution['tax']->subtract($allocated[$key]['tax']);
                } else {
                    $base = $this->proportional($contribution['base'], $ratio, $total);
                    $tax = $this->proportional($contribution['tax'], $ratio, $total);
                }

                $allocated[$key]['base'] = $allocated[$key]['base']->add($base);
                $allocated[$key]['tax'] = $allocated[$key]['tax']->add($tax);

                $shares[] = [
                    'key' => (string) $key,
                    'base' => $base,
                    'tax' => $tax,
                    'settledAt' => $settlement->settledAt,
                ];
            }
        }

        return $shares;
    }

    private function proportional(Money $total, BigDecimal $part, BigDecimal $whole): Money
    {
        if ($whole->isZero()) {
            return Money::zero($this->baseCurrency);
        }

        return Money::fromCalculation(
            BigDecimal::of($total->amountAsString())
                ->multipliedBy($part)
                ->dividedBy($whole, 10, RoundingMode::HalfUp),
            $this->baseCurrency,
        );
    }

    /**
     * Does a date fall in the requested filing period?
     *
     * Three windows, and which one applies is the caller's to say: a month, a quarter, or — when
     * neither is given — the whole year. The month matters more than it looks: for a business above
     * the threshold the monthly period is not a convenience but the prescribed one, and the only
     * alternative an app had was to call twice cumulatively and subtract. That difference is not the
     * period's figure once cash-basis taxation or a reversal is involved, which is why it had to
     * become a window here rather than arithmetic there.
     */
    private static function inPeriod(CalendarDate $date, int $year, int $quarter, int $month): bool
    {
        if ($date->year() !== $year) {
            return false;
        }

        if ($month !== 0) {
            return $date->month() === $month;
        }

        return $quarter === 0 || intdiv($date->month() - 1, 3) + 1 === $quarter;
    }

}
