<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Projection;

use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Ledger\Side;
use Rechnungswesen\Core\Port\AccountRepository;
use Rechnungswesen\Core\Port\JournalRepository;
use Rechnungswesen\Core\Shared\AccountNumber;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\Money;

/**
 * Kontoblatt: alle Bewegungen eines Kontos im Geschäftsjahr mit
 * laufendem Saldo. Anfangsbestand = kumulierte Vorjahre für
 * Bestandskonten, null für Erfolgskonten (api.md Zeitraum-Semantik).
 * Ordnung: sequenceNumber — die einzige autoritative (determinismus.md §3).
 */
final readonly class AccountSheetProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
    ) {
    }

    /**
     * @param array<string, mixed> $params account, fiscalYear, throughPeriod?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $number = is_string($params['account'] ?? null) ? $params['account'] : '';
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : 0;
        $throughPeriod = is_int($params['throughPeriod'] ?? null) ? $params['throughPeriod'] : PHP_INT_MAX;

        $account = $this->accounts->byNumber(AccountNumber::of($number));
        if ($account === null) {
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf('Konto %s existiert nicht', $number));
        }

        $opening = Money::zero($this->baseCurrency);

        if ($account->type->isBalanceCarrying()) {
            foreach ($this->journal->all() as $entry) {
                if ($entry->periodRef->fiscalYear >= $fiscalYear) {
                    continue;
                }

                foreach ($entry->lines() as $line) {
                    if (!$line->accountId->equals($account->id)) {
                        continue;
                    }

                    $opening = $line->side === Side::Debit
                        ? $opening->add($line->money)
                        : $opening->subtract($line->money);
                }
            }
        }

        $running = $opening;
        $lines = [];

        foreach ($this->journal->forFiscalYear($fiscalYear) as $entry) {
            if ($entry->periodRef->period > $throughPeriod) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                if (!$line->accountId->equals($account->id)) {
                    continue;
                }

                $running = $line->side === Side::Debit
                    ? $running->add($line->money)
                    : $running->subtract($line->money);

                $lines[] = [
                    'sequenceNumber' => $entry->sequenceNumber,
                    'entryDate' => $entry->entryDate->iso,
                    'text' => $entry->text(),
                    'side' => $line->side->value,
                    'money' => $line->money->jsonSerialize(),
                    'runningBalance' => $running->amountAsString(),
                ];
            }
        }

        return [
            'account' => $account->number->value,
            'name' => $account->name,
            'openingBalance' => $opening->amountAsString(),
            'lines' => $lines,
            'closingBalance' => $running->amountAsString(),
        ];
    }
}
