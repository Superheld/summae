<?php

declare(strict_types=1);

namespace Summae\Core\Projection;

use Summae\Core\DomainError;
use Summae\Core\Ledger\Side;
use Summae\Core\Mapping\MappingRegistry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Shared\CalendarDate;
use Summae\Core\Shared\Currency;
use Summae\Core\Shared\Money;

/**
 * Bilanz als Projektion (SF-10): kumulativ zum Stichtag.
 *
 * Position mit `includesNetIncome: true` enthält die kumulierten
 * Jahresergebnisse bis zum Stichtag PLUS den Saldo der eigenen Konten
 * (result_allocation, v0.4 G6) — das noch nicht verwendete Ergebnis.
 * Bilanzidentität by construction (api.md, Review G1).
 *
 * Seitenzuordnung (v0.5/F-007): `side: assets|liabilitiesAndEquity` am
 * Mapping-Wurzelknoten; assets = Soll−Haben, liabilitiesAndEquity =
 * Haben−Soll. Default ohne side: assets.
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
     * @param array<string, mixed> $params asOf, mapping, incomeMapping?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $asOf = is_string($params['asOf'] ?? null) ? CalendarDate::of($params['asOf']) : null;
        $mappingId = is_string($params['mapping'] ?? null) ? $params['mapping'] : '';

        $mapping = $this->mappings->byId($mappingId)
            ?? throw new DomainError('E_MAPPING_OVERLAP', sprintf('Mapping "%s" ist nicht geladen', $mappingId));

        $zero = Money::zero($this->baseCurrency);

        /** @var array<string, Money> $debits Kontonummer -> Soll */
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

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null) {
                    continue;
                }

                if (!$account->type->isBalanceCarrying()) {
                    // Kumulierte Jahresergebnisse (Haben − Soll über alle Jahre).
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
            // v0.5/F-007: Seite kommt aus `side` am Wurzelknoten, nicht aus der Reihenfolge.
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
