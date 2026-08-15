<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Side;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;

/**
 * Balance sheet as a projection (SF-10): cumulative as of the reporting date.
 *
 * A position with `includesNetIncome: true` contains the cumulative
 * net income up to the reporting date PLUS the balance of its own accounts
 * (result_allocation, v0.4 G6) — the result not yet appropriated.
 * Balance-sheet identity by construction (api.md, Review G1).
 *
 * Side assignment (v0.5/F-007): `side: assets|liabilitiesAndEquity` at the
 * mapping root node; assets = debit−credit, liabilitiesAndEquity =
 * credit−debit. Default without side: assets.
 */
final readonly class BalanceSheetProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
        private MappingRegistry $mappings,
    ) {
    }

    /**
     * @param array<string, mixed> $params asOf, fiscalYear, mapping, incomeMapping?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $asOf = is_string($params['asOf'] ?? null) ? CalendarDate::of($params['asOf']) : null;
        $mappingId = is_string($params['mapping'] ?? null) ? $params['mapping'] : '';
        // `fiscalYear` used to be read by nobody here: the handbook, the cheat sheet and the
        // gated scenarios all passed it, and the projection silently reported the whole journal
        // instead — two different years returned byte-identical balance sheets.
        //
        // It scopes CUMULATIVELY (everything up to and including that year), i.e. "as at the end
        // of fiscal year N", not "movements of year N". A balance sheet is a snapshot and must
        // balance; applying trialBalance's G1 rule here (income accounts restart each year) tears
        // a hole exactly the size of the prior year's result, because summae deliberately writes
        // no closing entries (`closeFiscalYear` is a pure status change), so that result was never
        // carried into equity. Cumulative keeps assets == liabilities+equity in every year.
        $fiscalYear = Parameters::integerOrNull($params['fiscalYear'] ?? null);

        // A missing or unknown mapping is a caller mistake, not an overlap: reporting it as
        // E_MAPPING_OVERLAP (the code for two positions claiming the same account) sent operators
        // hunting the wrong thing, and an omitted parameter produced 'Mapping "" is not loaded'.
        $mapping = $this->mappings->byId($mappingId)
            ?? throw new DomainError(
                'E_INPUT_INVALID',
                $mappingId === ''
                    ? 'balanceSheet requires the parameter "mapping"'
                    : sprintf('mapping "%s" is not loaded', $mappingId),
                ['mapping' => $mappingId],
            );

        $zero = Money::zero($this->baseCurrency);

        /** @var array<string, Money> $debits account number -> debit */
        $debits = [];
        /** @var array<string, Money> $credits */
        $credits = [];
        /** @var array<string, true> $touchedAccounts */
        $touchedAccounts = [];
        $netIncome = $zero;

        foreach ($this->journal->all() as $entry) {
            if ($asOf !== null && $entry->entryDate->isAfter($asOf)) {
                continue;
            }

            if ($fiscalYear !== null && $entry->periodRef->fiscalYear > $fiscalYear) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null) {
                    continue;
                }

                if (!$account->type->isBalanceCarrying()) {
                    // Cumulative net income (credit − debit over all years).
                    $netIncome = $line->side === Side::Credit
                        ? $netIncome->add($line->money)
                        : $netIncome->subtract($line->money);
                    continue;
                }

                $key = $account->number->value;
                if ($line->side === Side::Debit) {
                    $debits[$key] = ($debits[$key] ?? $zero)->add($line->money);
                } else {
                    $credits[$key] = ($credits[$key] ?? $zero)->add($line->money);
                }

                $touchedAccounts[$key] = true;
            }
        }

        $sections = ['assets' => [], 'liabilitiesAndEquity' => []];
        $totals = ['assets' => $zero, 'liabilitiesAndEquity' => $zero];

        foreach ($mapping->leaves as $leaf) {
            // v0.5/F-007: side comes from `side` at the root node, not from the order.
            $section = $leaf['side'] === 'liabilitiesAndEquity' ? 'liabilitiesAndEquity' : 'assets';

            $amount = $zero;
            $touched = false;

            foreach (array_keys($debits + $credits) as $number) {
                $number = (string) $number;
                if (!$this->leafMatches($leaf, $number)) {
                    continue;
                }

                $debit = $debits[$number] ?? $zero;
                $credit = $credits[$number] ?? $zero;
                $amount = $section === 'assets'
                    ? $amount->add($debit)->subtract($credit)
                    : $amount->add($credit)->subtract($debit);
                $touched = $touched || isset($touchedAccounts[$number]);
            }

            if ($leaf['includesNetIncome']) {
                $amount = $amount->add($netIncome);
                $touched = $touched || !$netIncome->isZero();
            }

            if ($amount->isZero() && !$touched) {
                continue;
            }

            $sections[$section][] = [
                'key' => $leaf['key'],
                'label' => $leaf['label'],
                'amount' => $amount->amountAsString(),
            ];
            $totals[$section] = $totals[$section]->add($amount);
        }

        return [
            'assets' => $sections['assets'],
            'assetsTotal' => $totals['assets']->amountAsString(),
            'liabilitiesAndEquity' => $sections['liabilitiesAndEquity'],
            'liabilitiesAndEquityTotal' => $totals['liabilitiesAndEquity']->amountAsString(),
        ];
    }

    /**
     * @param array{key: string, label: string, side: ?string, ranges: list<array{from: string, to: string}>, numbers: list<string>, includeNonCash: bool, includesNetIncome: bool, parents: list<string>} $leaf
     */
    private function leafMatches(array $leaf, string $accountNumber): bool
    {
        if (in_array($accountNumber, $leaf['numbers'], true)) {
            return true;
        }

        foreach ($leaf['ranges'] as $range) {
            if (strcmp($accountNumber, $range['from']) >= 0 && strcmp($accountNumber, $range['to']) <= 0) {
                return true;
            }
        }

        return false;
    }
}
