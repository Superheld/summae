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
    /** Catch-all key, identical to the one importMapping already assigns (error catalogue). */
    private const string UNASSIGNED = '_unassigned';

    /** Neutral, jurisdiction-free — there is no mapping entry to take a label from. */
    private const string UNASSIGNED_LABEL = 'Unassigned';

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
        // Accounts the mapping does not cover. A gap is not an error (error catalogue: gapWarnings[]
        // + catch-all, the same treatment importMapping gives it) — but it must not be silence
        // either: the amount used to be dropped here while the balance sheet, which sums income
        // accounts by type, kept counting it. Two reports, same money, different answers, no hint.
        /** @var array<string, true> $gapAccounts */
        $gapAccounts = [];

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
                $key = $leaf['key'] ?? self::UNASSIGNED;

                if ($leaf === null) {
                    $gapAccounts[$account->number->value] = true;
                }

                $signed = $line->side === Side::Credit ? $line->money : $line->money->negate();
                $amounts[$key] = ($amounts[$key] ?? $zero)->add($signed);
                $touched[$key] = true;
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

        // The catch-all comes last and only when it carries something — an empty one would put a
        // "nothing is missing" line into every report, which is noise rather than information.
        if (isset($touched[self::UNASSIGNED])) {
            $unassigned = $amounts[self::UNASSIGNED] ?? $zero;
            $netIncome = $netIncome->add($unassigned);
            $positions[] = [
                'key' => self::UNASSIGNED,
                'label' => self::UNASSIGNED_LABEL,
                'amount' => $unassigned->amountAsString(),
            ];
        }

        // Sorted by account number (code points) so the list is deterministic; it names the accounts
        // that actually contributed, which is what a reader of THIS report needs. Whether a mapping
        // covers every account regardless of postings is importMapping's question, and it answers it.
        // The cast back to string is not cosmetic: PHP silently turns a numeric string used as an
        // array key into an int, so account "9100" came out of array_keys() as 9100 and the export
        // carried a number where every other implementation carries a string.
        $gaps = array_map(strval(...), array_keys($gapAccounts));
        sort($gaps, SORT_STRING);

        $gapWarnings = [];
        foreach ($gaps as $account) {
            $gapWarnings[] = ['account' => $account, 'assignedTo' => self::UNASSIGNED];
        }

        return [
            'positions' => $positions,
            'netIncome' => $netIncome->amountAsString(),
            'gapWarnings' => $gapWarnings,
        ];
    }
}
