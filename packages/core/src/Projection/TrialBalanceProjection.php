<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Projection;

use Rechnungswesen\Core\Ledger\Side;
use Rechnungswesen\Core\Port\AccountRepository;
use Rechnungswesen\Core\Port\JournalRepository;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\Money;

/**
 * Summen- und Saldenliste (SuSa).
 *
 * Zeitraum-Semantik (api.md v0.3, Review G1):
 * - Bestandskonten (asset/liability/equity): kumulativ ab Datenbestand-
 *   Beginn — Saldovortrag implizit, kein SBK/EBK.
 * - Erfolgskonten (expense/revenue): nur Verkehrszahlen des angefragten
 *   Geschäftsjahres; im neuen Jahr starten sie bei null — per Definition
 *   der Projektion, nicht per Buchung.
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

        /** @var array<string, array{debit: Money, credit: Money, currentYearActivity: bool}> $totals */
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

                // Erfolgskonten zählen nur im angefragten Geschäftsjahr.
                if ($isPriorYear && !$account->type->isBalanceCarrying()) {
                    continue;
                }

                $key = $account->number->value;
                $totals[$key] ??= ['debit' => $zero, 'credit' => $zero, 'currentYearActivity' => false];

                if ($line->side === Side::Debit) {
                    $totals[$key]['debit'] = $totals[$key]['debit']->add($line->money);
                } else {
                    $totals[$key]['credit'] = $totals[$key]['credit']->add($line->money);
                }

                if ($isCurrentScope) {
                    $totals[$key]['currentYearActivity'] = true;
                }
            }
        }

        if ($includeZeroBalances) {
            foreach ($this->accounts->all() as $account) {
                $totals[$account->number->value] ??= [
                    'debit' => $zero,
                    'credit' => $zero,
                    'currentYearActivity' => false,
                ];
            }
        }

        // PHP macht numerische String-Keys zu Ints — Kontonummern sind Strings!
        $numbers = array_map(strval(...), array_keys($totals));
        usort($numbers, static fn (string $a, string $b): int => strcmp($a, $b));

        $rows = [];
        foreach ($numbers as $number) {
            $total = $totals[$number];
            $balance = $total['debit']->subtract($total['credit']);

            if (!$includeZeroBalances && $balance->isZero() && !$total['currentYearActivity']) {
                continue;
            }

            $rows[] = [
                'account' => $number,
                'debitTotal' => $total['debit']->amountAsString(),
                'creditTotal' => $total['credit']->amountAsString(),
                'balance' => $balance->amountAsString(),
            ];
        }

        return ['rows' => $rows];
    }
}
