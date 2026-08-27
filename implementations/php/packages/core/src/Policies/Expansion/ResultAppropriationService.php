<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion;

use Summae\Core\DomainError;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Policies\Projection\UnappropriatedResultProjection;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Side;

/**
 * Appropriation of profit as a named operation (F-CORE-024/SF-25, expansion).
 *
 * **Why this is not something `closeFiscalYear` does.** Appropriating a result is a *resolution*
 * — § 29 GmbHG, § 174 AktG and their equivalents elsewhere — and which part is distributed, put
 * into reserves or carried forward is not something a library can derive from the books. It is
 * also dated when the resolution is passed, which normally falls in the *following* fiscal year;
 * a close that booked it would have to invent a date it does not have. So summae does not decide,
 * it expands: the caller states the decision, the pack supplies the accounts.
 *
 * Before this operation existed the caller had to know the account numbers — the one part of the
 * bookkeeping where an embedding still had to (`2300 an 2100`). That is exactly where an agent or
 * an application guesses, and a guessed account number is a wrong posting rather than an error
 * message. Here a wrong target is refused by name and a wrong amount by the books.
 *
 * **What may be appropriated** is the result *not yet appropriated*, and that figure is not computed
 * here: `unappropriatedResult` publishes it and this operation asks the same projection, so the
 * number a user reads and the number this refuses against cannot drift apart. It is also the figure
 * the balance sheet reports in its `includesNetIncome` position.
 *
 * A loss appropriates the other way round (allocation account in credit), and the amounts stay
 * positive in the input either way: the direction follows from the books, not from a sign the
 * caller has to get right.
 *
 * The SAME shape lives in the Node result-appropriation-service.ts.
 */
final class ResultAppropriationService
{
    /** @var array<string, mixed> */
    private array $ruleModule = [];

    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journal,
        private readonly Ledger $ledger,
        private readonly AuditWriter $audit,
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
    public function appropriate(array $input): array
    {
        $actor = $this->audit->actorOf($input);
        ['allocationAccount' => $allocationAccount, 'targets' => $offered] = $this->plug();

        $fiscalYear = $input['fiscalYear'] ?? null;
        if (!is_int($fiscalYear)) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'appropriateResult requires the parameter "fiscalYear"',
                ['fiscalYear' => DomainError::rejectedValue($input['fiscalYear'] ?? null)],
            );
        }

        $requested = $this->parseAppropriations($input['appropriations'] ?? null, $offered);
        $available = (new UnappropriatedResultProjection($this->baseCurrency, $this->accounts, $this->journal))
            ->available($fiscalYear);

        // Nothing to appropriate is refused rather than posted as zero: an entry that moves nothing
        // would sit in the books claiming a resolution took effect.
        if ($available->isZero()) {
            throw new DomainError(
                'E_APPROPRIATION_EXCEEDS_RESULT',
                sprintf('Fiscal year %d has no unappropriated result', $fiscalYear),
                ['fiscalYear' => $fiscalYear, 'available' => $available->amountAsString()],
            );
        }

        $isProfit = !$available->isNegative();
        $capacity = $isProfit ? $available : $available->negate();
        $total = Money::zero($this->baseCurrency);
        foreach ($requested as $item) {
            $total = $total->add($item['money']);
        }

        if ($total->compareTo($capacity) > 0) {
            throw new DomainError(
                'E_APPROPRIATION_EXCEEDS_RESULT',
                sprintf(
                    'Appropriation of %s exceeds the unappropriated result of %s',
                    $total->amountAsString(),
                    $available->amountAsString(),
                ),
                [
                    'requested' => $total->amountAsString(),
                    'available' => $available->amountAsString(),
                    'fiscalYear' => $fiscalYear,
                ],
            );
        }

        // A profit leaves the allocation account in debit and reaches its targets in credit; a loss
        // does the same journey backwards. The caller states amounts, never sides.
        $allocationSide = $isProfit ? Side::Debit->value : Side::Credit->value;
        $targetSide = $isProfit ? Side::Credit->value : Side::Debit->value;

        $lines = [[
            'account' => $allocationAccount,
            'side' => $allocationSide,
            'money' => $total->jsonSerialize(),
        ]];
        foreach ($requested as $item) {
            $lines[] = [
                'account' => $item['account'],
                'side' => $targetSide,
                'money' => $item['money']->jsonSerialize(),
            ];
        }

        $text = is_string($input['text'] ?? null) ? $input['text'] : sprintf('Appropriation of the result %d', $fiscalYear);
        $postInput = [
            'entryDate' => $input['entryDate'] ?? null,
            'voucherId' => $input['voucherId'] ?? null,
            'text' => $text,
            'lines' => $lines,
        ];
        if ($actor !== '') {
            $postInput['actor'] = $actor;
        }
        $result = $this->ledger->post($postInput);

        $remaining = $isProfit ? $available->subtract($total) : $available->add($total);

        $appropriated = [];
        foreach ($requested as $item) {
            $appropriated[] = [
                'target' => $item['target'],
                'account' => $item['account'],
                'money' => $item['money']->jsonSerialize(),
            ];
        }

        return [
            'entry' => $result->entry->jsonSerialize(),
            'fiscalYear' => $fiscalYear,
            'appropriated' => $appropriated,
            'remaining' => $remaining->amountAsString(),
        ];
    }

    /**
     * What the pack offers, or the refusal that says it offers nothing.
     *
     * @return array{allocationAccount: string, targets: array<string, string>}
     */
    private function plug(): array
    {
        $data = is_array($this->ruleModule['resultAppropriation'] ?? null) ? $this->ruleModule['resultAppropriation'] : null;
        $allocationAccount = $data === null ? null : ($data['allocationAccount'] ?? null);
        if ($data === null || !is_string($allocationAccount)) {
            throw new DomainError(
                'E_APPROPRIATION_UNSUPPORTED',
                'The pack declares no result appropriation, so summae does not know which accounts a resolution books against',
            );
        }

        $targets = [];
        foreach (is_array($data['targets'] ?? null) ? $data['targets'] : [] as $name => $target) {
            $account = is_array($target) ? ($target['account'] ?? null) : null;
            if (is_string($account)) {
                $targets[(string) $name] = $account;
            }
        }

        return ['allocationAccount' => $allocationAccount, 'targets' => $targets];
    }

    /**
     * @param array<string, string> $offered
     *
     * @return list<array{target: string, account: string, money: Money}>
     */
    private function parseAppropriations(mixed $value, array $offered): array
    {
        $items = is_array($value) ? array_values($value) : [];
        if ($items === []) {
            throw new DomainError('E_INPUT_INVALID', 'appropriateResult without appropriations');
        }

        $parsed = [];
        $seen = [];
        foreach ($items as $item) {
            $target = is_array($item) ? ($item['target'] ?? null) : null;
            if (!is_string($target)) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    'An appropriation without a target',
                    ['target' => DomainError::rejectedValue(is_array($item) ? ($item['target'] ?? null) : $item)],
                );
            }
            if (!isset($offered[$target])) {
                // Named rather than validated against a fixed list: which targets exist is the
                // pack's answer, and a jurisdiction that knows no distribution offers none.
                $names = array_keys($offered);
                sort($names);
                throw new DomainError(
                    'E_APPROPRIATION_UNSUPPORTED',
                    sprintf('The pack offers no appropriation target "%s"', $target),
                    ['target' => $target, 'offered' => $names],
                );
            }
            // Twice the same target would post two lines on one account in one entry — legal, and
            // a sign the caller lost track of its own decision.
            if (isset($seen[$target])) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    sprintf('Appropriation target "%s" given twice', $target),
                    ['target' => $target],
                );
            }
            $seen[$target] = true;

            $parsed[] = [
                'target' => $target,
                'account' => $offered[$target],
                'money' => $this->parseMoney($item['money'] ?? null, $target),
            ];
        }

        return $parsed;
    }

    /**
     * Amounts arrive in the same shape every posting line uses, and are refused with the same
     * strictness — including the currency, which v1 pins to the tenant's.
     */
    private function parseMoney(mixed $value, string $target): Money
    {
        $amount = is_array($value) ? ($value['amount'] ?? null) : null;
        $currency = is_array($value) ? ($value['currency'] ?? null) : null;
        if (!is_string($amount) || $currency !== $this->baseCurrency->code) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('Appropriation "%s": money missing or not in %s', $target, $this->baseCurrency->code),
                ['target' => $target, 'money' => DomainError::rejectedValue($value)],
            );
        }

        try {
            $money = Money::of($amount, $this->baseCurrency);
        } catch (InvalidValue) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('Appropriation "%s": "%s" is not a valid amount', $target, $amount),
                ['target' => $target, 'money' => DomainError::rejectedValue($value)],
            );
        }

        if (!$money->isPositive()) {
            throw new DomainError(
                'E_INPUT_INVALID',
                sprintf('Appropriation "%s": amount must be > 0', $target),
                ['target' => $target, 'money' => DomainError::rejectedValue($value)],
            );
        }

        return $money;
    }

    /** Which targets this tenant can appropriate to — for a caller that wants to offer a choice. */
    /** @return list<string> */
    public function offeredTargets(): array
    {
        $data = is_array($this->ruleModule['resultAppropriation'] ?? null) ? $this->ruleModule['resultAppropriation'] : null;
        if ($data === null) {
            return [];
        }
        $targets = is_array($data['targets'] ?? null) ? $data['targets'] : [];
        $names = array_map(static fn ($n): string => (string) $n, array_keys($targets));
        sort($names);

        return $names;
    }
}
