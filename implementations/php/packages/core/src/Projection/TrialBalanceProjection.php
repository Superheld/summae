<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Projection;

use Rechnungswesen\Core\Ledger\Side;
use Rechnungswesen\Core\Port\AccountRepository;
use Rechnungswesen\Core\Port\JournalRepository;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\Money;

/**
 * Summen- und Saldenliste (SuSa) — Spalten verbindlich (api.md v0.4):
 * - openingBalance: kumulierter Saldo VOR dem Geschäftsjahr
 *   (Saldovortrag implizit; 0 bei Erfolgskonten — Review G1)
 * - debitTotal/creditTotal: Verkehrszahlen DES Zeitraums (GJ bis Periode)
 * - balance = openingBalance + debitTotal − creditTotal
 *
 * Saldo-Konvention: Soll minus Haben (Soll-Salden positiv).
 * Sortierung: Kontonummer nach Codepoints (determinismus.md §3).
 */
final readonly class TrialBalanceProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
    ) {
    }

    /**
     * @param array<string, mixed> $params fiscalYear, throughPeriod, includeZeroBalances?
     *
     * @return array{rows: list<array<string, string>>}
     */
    public function compute(array $params): array
    {
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : 0;
        $throughPeriod = is_int($params['throughPeriod'] ?? null) ? $params['throughPeriod'] : PHP_INT_MAX;
        $includeZeroBalances = ($params['includeZeroBalances'] ?? false) === true;

        $zero = Money::zero($this->baseCurrency);

        /** @var array<string, array{opening: Money, debit: Money, credit: Money, touched: bool}> $totals */
        $totals = [];

        foreach ($this->journal->all() as $entry) {
            $entryYear = $entry->periodRef->fiscalYear;
            $entryPeriod = $entry->periodRef->period;

            $isPriorYear = $entryYear < $fiscalYear;
            $isCurrentScope = $entryYear === $fiscalYear && $entryPeriod <= $throughPeriod;

            if (!$isPriorYear && !$isCurrentScope) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null) {
                    continue;
                }

                // Erfolgskonten starten je Geschäftsjahr bei null (G1).
                if ($isPriorYear && !$account->type->isBalanceCarrying()) {
                    continue;
                }

                $key = $account->number->value;
                $totals[$key] ??= ['opening' => $zero, 'debit' => $zero, 'credit' => $zero, 'touched' => false];

                if ($isPriorYear) {
                    $totals[$key]['opening'] = $line->side === Side::Debit
                        ? $totals[$key]['opening']->add($line->money)
                        : $totals[$key]['opening']->subtract($line->money);
                    continue;
                }

                if ($line->side === Side::Debit) {
                    $totals[$key]['debit'] = $totals[$key]['debit']->add($line->money);
                } else {
                    $totals[$key]['credit'] = $totals[$key]['credit']->add($line->money);
                }

                $totals[$key]['touched'] = true;
            }
        }

        if ($includeZeroBalances) {
            foreach ($this->accounts->all() as $account) {
                $totals[$account->number->value] ??= [
                    'opening' => $zero,
                    'debit' => $zero,
                    'credit' => $zero,
                    'touched' => false,
                ];
            }
        }

        // PHP macht numerische String-Keys zu Ints — Kontonummern sind Strings!
        $numbers = array_map(strval(...), array_keys($totals));
        usort($numbers, static fn (string $a, string $b): int => strcmp($a, $b));

        $rows = [];
        foreach ($numbers as $number) {
            $total = $totals[$number];
            $balance = $total['opening']->add($total['debit'])->subtract($total['credit']);

            if (!$includeZeroBalances && $balance->isZero() && !$total['touched']) {
                continue;
            }

            $rows[] = [
                'account' => $number,
                'openingBalance' => $total['opening']->amountAsString(),
                'debitTotal' => $total['debit']->amountAsString(),
                'creditTotal' => $total['credit']->amountAsString(),
                'balance' => $balance->amountAsString(),
            ];
        }

        return ['rows' => $rows];
    }
}
