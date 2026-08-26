<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Substrate\AccountType;
use Summae\Core\Policies\Projection\Mapping\Unassigned;
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
 * Side assignment (v0.5/SPEC-007): `side: assets|liabilitiesAndEquity` at the
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
            ?? throw $this->mappingRefusal('balanceSheet', $mappingId);

        $zero = Money::zero($this->baseCurrency);

        /** @var array<string, Money> $debits account number -> debit */
        $debits = [];
        /** @var array<string, Money> $credits */
        $credits = [];
        /** @var array<string, true> $touchedAccounts */
        $touchedAccounts = [];
        // Which side an account belongs on when no position claims it. Taken from the account TYPE,
        // which is jurisdiction-free and always present — the mapping cannot answer it, since the
        // whole problem is that the mapping says nothing about this account.
        /** @var array<string, string> $sectionOf */
        $sectionOf = [];
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
                $sectionOf[$key] = $account->type === AccountType::Asset ? 'assets' : 'liabilitiesAndEquity';

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

        // An account no position matches used to be visited by nobody: the loop below runs over the
        // POSITIONS and pulls what each one matches, so an unmatched account landed in neither total
        // and the sheet stopped balancing without saying so.
        $unmatched = [];
        foreach (array_keys($debits + $credits) as $number) {
            $number = (string) $number;
            foreach ($mapping->leaves as $leaf) {
                if ($this->leafMatches($leaf, $number)) {
                    continue 2;
                }
            }

            $unmatched[] = $number;
        }
        sort($unmatched, SORT_STRING);

        foreach ($mapping->leaves as $leaf) {
            // v0.5/SPEC-007: side comes from `side` at the root node, not from the order.
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

        // The catch-all per section, appended last and only when it carries something. Amounts
        // follow the same sign rule as the section they land in, so the identity holds again.
        foreach (['assets', 'liabilitiesAndEquity'] as $section) {
            $amount = $zero;
            $touched = false;

            foreach ($unmatched as $number) {
                if (($sectionOf[$number] ?? 'assets') !== $section) {
                    continue;
                }

                $debit = $debits[$number] ?? $zero;
                $credit = $credits[$number] ?? $zero;
                $amount = $section === 'assets'
                    ? $amount->add($debit)->subtract($credit)
                    : $amount->add($credit)->subtract($debit);
                $touched = $touched || isset($touchedAccounts[$number]);
            }

            if (!$touched) {
                continue;
            }

            $sections[$section][] = [
                'key' => Unassigned::KEY,
                'label' => Unassigned::LABEL,
                'amount' => $amount->amountAsString(),
            ];
            $totals[$section] = $totals[$section]->add($amount);
        }

        $gapWarnings = [];
        foreach ($unmatched as $account) {
            $gapWarnings[] = ['account' => $account, 'assignedTo' => Unassigned::KEY];
        }

        return [
            'assets' => $sections['assets'],
            'assetsTotal' => $totals['assets']->amountAsString(),
            'liabilitiesAndEquity' => $sections['liabilitiesAndEquity'],
            'liabilitiesAndEquityTotal' => $totals['liabilitiesAndEquity']->amountAsString(),
            'gapWarnings' => $gapWarnings,
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

    /**
     * Which mappings this tenant could use — part of the refusal, because the refusal is the only
     * place a caller learns it. A `default` tenant has none at all: the neutral pack ships no
     * mapping module, since a jurisdiction-free chart has no lawful statement layout to ship
     * (IMPL-032). That is a legitimate answer and used to arrive as "requires the parameter
     * mapping", which reads as *you forgot something* rather than *this pack cannot do this*.
     */
    private function mappingRefusal(string $projection, string $mappingId): DomainError
    {
        $available = array_map(
            static fn (array $summary): string => $summary['id'],
            $this->mappings->summaries(),
        );

        if ($available === []) {
            return new DomainError(
                'E_INPUT_INVALID',
                sprintf(
                    '%s needs a mapping and this tenant has none: its pack ships no mapping module, so one has to be loaded with importMapping',
                    $projection,
                ),
                ['mapping' => $mappingId, 'available' => $available],
            );
        }

        return new DomainError(
            'E_INPUT_INVALID',
            $mappingId === ''
                ? sprintf('%s requires the parameter "mapping"', $projection)
                : sprintf('mapping "%s" is not loaded', $mappingId),
            ['mapping' => $mappingId, 'available' => $available],
        );
    }
}
