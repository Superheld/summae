<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Side;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;

/**
 * Income statement as a projection over a mapping (SF-09). Exactly one fiscal year;
 * v0.4: fromPeriod/throughPeriod restrict the range (monthly income statement as
 * BWA basis), the yearly view stays the default.
 *
 * Sign: credit − debit (revenue positive, expense negative);
 * netIncome = sum of the positions.
 */
final readonly class IncomeStatementProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
        private MappingRegistry $mappings,
    ) {
    }

    /**
     * @param array<string, mixed> $params fiscalYear, fromPeriod?, throughPeriod?, mapping
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $fiscalYear = Parameters::integerOr($params['fiscalYear'] ?? null, 0);
        $fromPeriod = Parameters::integerOr($params['fromPeriod'] ?? null, 1);
        $throughPeriod = Parameters::integerOr($params['throughPeriod'] ?? null, PHP_INT_MAX);
        $mappingId = is_string($params['mapping'] ?? null) ? $params['mapping'] : '';

        // A missing or unknown mapping is a caller mistake, not an overlap: reporting it as
        // E_MAPPING_OVERLAP (the code for two positions claiming the same account) sent operators
        // hunting the wrong thing, and an omitted parameter produced 'Mapping "" is not loaded'.
        $mapping = $this->mappings->byId($mappingId)
            ?? throw new DomainError(
                'E_INPUT_INVALID',
                $mappingId === ''
                    ? 'incomeStatement requires the parameter "mapping"'
                    : sprintf('mapping "%s" is not loaded', $mappingId),
                ['mapping' => $mappingId],
            );

        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, Money> $amounts key -> amount */
        $amounts = [];
        /** @var array<string, true> $touched */
        $touched = [];

        foreach ($this->journal->forFiscalYear($fiscalYear) as $entry) {
            $period = $entry->periodRef->period;
            if ($period < $fromPeriod || $period > $throughPeriod) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null || $account->type->isBalanceCarrying()) {
                    continue;
                }

                $leaf = $mapping->leafFor($account->number->value);
                if ($leaf === null) {
                    continue;
                }

                $signed = $line->side === Side::Credit ? $line->money : $line->money->negate();
                $amounts[$leaf['key']] = ($amounts[$leaf['key']] ?? $zero)->add($signed);
                $touched[$leaf['key']] = true;
            }
        }

        $positions = [];
        $netIncome = $zero;

        foreach ($mapping->leaves as $leaf) {
            $amount = $amounts[$leaf['key']] ?? $zero;
            $netIncome = $netIncome->add($amount);

            if ($amount->isZero() && !isset($touched[$leaf['key']])) {
                continue;
            }

            $positions[] = [
                'key' => $leaf['key'],
                'label' => $leaf['label'],
                'amount' => $amount->amountAsString(),
            ];
        }

        return [
            'positions' => $positions,
            'netIncome' => $netIncome->amountAsString(),
        ];
    }
}
