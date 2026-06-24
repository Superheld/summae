<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\EntryLine;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Side;
use Summae\Core\Substrate\Timestamp;

/**
 * AICPA Audit Data Standard (General Ledger) export — the US counterpart to journalExport
 * (GoBD-Z3, German) and datevExport (DATEV, German). The US has no statutory GL export format;
 * the AICPA ADS is the voluntary standard a US auditor expects. Emits the three GL streams with
 * the standard's field names (JSON form, per the AICPA-ADS/AuditData-API schema): `journals`
 * (GLDetail), `trialBalance` (GLAccountBalance), `accounts` (chart). ADS line amounts are SIGNED
 * (debit positive, credit negative) — no debit/credit indicator. Mirror of the Node projection.
 */
final readonly class AuditDataExportProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private JournalRepository $journal,
        private AccountRepository $accounts,
        private VoucherRepository $vouchers,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : null;
        $inScope = $fiscalYear === null ? $this->journal->all() : $this->journal->forFiscalYear($fiscalYear);
        $prior = $fiscalYear === null ? [] : array_values(array_filter(
            $this->journal->all(),
            static fn (JournalEntry $e): bool => $e->periodRef->fiscalYear < $fiscalYear,
        ));
        $asOf = is_string($params['asOf'] ?? null) ? $params['asOf'] : $this->latestDate($inScope);

        return [
            'standard' => 'aicpa-ads-gl',
            'currency' => $this->baseCurrency->code,
            'journals' => array_map($this->journalRow(...), $inScope),
            'trialBalance' => $this->trialBalanceRows($prior, $inScope, $asOf, $fiscalYear),
            'accounts' => $this->accountRows(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function journalRow(JournalEntry $entry): array
    {
        $voucher = $this->vouchers->byId($entry->voucherId);
        $lines = [];
        foreach ($entry->lines() as $index => $line) {
            $lines[] = [
                'glAccountNumber' => $line->account->value,
                'journalIdLineNumber' => $entry->id->value . '-' . ($index + 1),
                'jeLineDescription' => $entry->text(),
                'transactionAmount' => $this->signed($line)->amountAsString(),
                'transactionCurrency' => $this->baseCurrency->code,
            ];
        }

        return [
            'journalId' => $entry->id->value,
            'effectiveDate' => $entry->entryDate->iso,
            'fiscalYear' => $entry->periodRef->fiscalYear,
            'period' => $entry->periodRef->period,
            'jeHeaderDescription' => $entry->text(),
            'source' => $voucher === null ? null : $voucher->voucherNumber,
            'enteredDate' => Timestamp::canonical($entry->recordedAt),
            'reversalIndicator' => $entry->reverses !== null,
            'reversalJournalId' => $entry->reverses?->value,
            'glLineItems' => $lines,
        ];
    }

    /** ADS convention: debit positive, credit negative. */
    private function signed(EntryLine $line): Money
    {
        return $line->side === Side::Debit ? $line->money : $line->money->negate();
    }

    /**
     * @param list<JournalEntry> $prior
     * @param list<JournalEntry> $current
     *
     * @return list<array<string, mixed>>
     */
    private function trialBalanceRows(array $prior, array $current, ?string $asOf, ?int $fiscalYear): array
    {
        $beginning = $this->signedSums($prior);
        $ending = $this->signedSums([...$prior, ...$current]);
        $numbers = array_keys($ending);
        sort($numbers, SORT_STRING);
        $zero = Money::zero($this->baseCurrency);

        $rows = [];
        foreach ($numbers as $number) {
            $number = (string) $number;
            $rows[] = [
                'glAccountNumber' => $number,
                'balanceAsOfDate' => $asOf,
                'fiscalYear' => $fiscalYear,
                'amountBeginning' => ($beginning[$number] ?? $zero)->amountAsString(),
                'amountEnding' => ($ending[$number] ?? $zero)->amountAsString(),
                'amountCurrency' => $this->baseCurrency->code,
            ];
        }

        return $rows;
    }

    /**
     * @param list<JournalEntry> $entries
     *
     * @return array<string, Money>
     */
    private function signedSums(array $entries): array
    {
        $sums = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines() as $line) {
                $key = $line->account->value;
                $sums[$key] = ($sums[$key] ?? Money::zero($this->baseCurrency))->add($this->signed($line));
            }
        }

        return $sums;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountRows(): array
    {
        $accounts = $this->accounts->all();
        usort($accounts, static fn (Account $a, Account $b): int => strcmp($a->number->value, $b->number->value));

        return array_map(static fn (Account $a): array => [
            'glAccountNumber' => $a->number->value,
            'glAccountName' => $a->name,
            'accountType' => $a->type->value,
            'accountSubtype' => $a->subtype,
            'parentGLAccountNumber' => null,
        ], $accounts);
    }

    /**
     * @param list<JournalEntry> $entries
     */
    private function latestDate(array $entries): ?string
    {
        $latest = null;
        foreach ($entries as $entry) {
            if ($latest === null || $entry->entryDate->iso > $latest) {
                $latest = $entry->entryDate->iso;
            }
        }

        return $latest;
    }
}
