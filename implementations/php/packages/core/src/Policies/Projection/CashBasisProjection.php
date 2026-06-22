<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Summae\Core\DomainError;
use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\AccountType;
use Summae\Core\Substrate\EntryLine;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\Side;
use Summae\Core\Policies\Projection\Mapping\Mapping;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;

/**
 * Cash-basis accounting (EÜR) as a projection over the double-entry journal — rules R1–R7
 * (euer-projektions-beweis.md, validated by the prototype):
 *
 * R1 Cash effect via money accounts; categories on open-item settlement
 *    via the OP link from the origin voucher (proportional on partial payment).
 * R2 10-day rule: recurring, paid AND due within the window
 *    22.12.–10.01. -> year of economic allocation.
 * R3 VAT/input tax cash-effective and income-relevant.
 * R4 Asset payment not deductible (depreciation comes via R7).
 * R5 Loans/private/pass-through items neutral.
 * R6 Category = mapping label, otherwise account name.
 * R7 includeNonCash positions count in the posting year without cash flow.
 *
 * Cash-basis accounting is bound to the calendar year: a deviating fiscal year ->
 * E_CASHBASIS_DEVIATING_FISCAL_YEAR.
 */
final readonly class CashBasisProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
        private OpenItemRepository $openItems,
        private VoucherRepository $vouchers,
        private FiscalYearRepository $fiscalYears,
        private MappingRegistry $mappings,
    ) {
    }

    /**
     * @param array<string, mixed> $params year, asOf?, mapping?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $year = is_int($params['year'] ?? null) ? $params['year'] : 0;
        $asOf = is_string($params['asOf'] ?? null) ? CalendarDate::of($params['asOf']) : null;
        $mapping = is_string($params['mapping'] ?? null) ? $this->mappings->byId($params['mapping']) : null;

        $this->assertCalendarYearFiscalYears($year);

        /** @var array<string, Money> $income */
        $income = [];
        /** @var array<string, Money> $expenses */
        $expenses = [];


        foreach ($this->journal->all() as $entry) {
            if ($asOf !== null && $entry->entryDate->isAfter($asOf)) {
                continue;
            }

            $bankFlow = $this->bankFlow($entry);

            if ($bankFlow->isZero()) {
                // R7: non-cash mandatory categories (posting year).
                if ($mapping === null || $entry->entryDate->year() !== $year) {
                    continue;
                }

                foreach ($entry->lines() as $line) {
                    $account = $this->accounts->byId($line->accountId);
                    if ($account === null) {
                        continue;
                    }

                    $leaf = $mapping->leafFor($account->number->value);
                    if ($leaf === null || !$leaf['includeNonCash']) {
                        continue;
                    }

                    if ($account->type === AccountType::Revenue || $account->subtype === 'tax_out') {
                        $signed = $line->side === Side::Credit ? $line->money : $line->money->negate();
                        $income = self::addTo($income, $leaf['label'], $signed);
                    } elseif ($account->type === AccountType::Expense || $account->subtype === 'tax_in') {
                        $signed = $line->side === Side::Debit ? $line->money : $line->money->negate();
                        $expenses = self::addTo($expenses, $leaf['label'], $signed);
                    }
                }

                continue;
            }

            // R1: cash-effective — target year (R2), source possibly via OP link.
            if ($this->assignYear($entry) !== $year) {
                continue;
            }

            $inflow = $bankFlow->isPositive();

            foreach ($this->sourceLines($entry) as $sourced) {
                $line = $sourced['line'];
                $ratio = $sourced['ratio'];
                $account = $this->accounts->byId($line->accountId);
                if ($account === null || in_array($account->subtype, ['bank', 'cash', 'transit', 'ar', 'ap'], true)) {
                    continue;
                }

                $amount = $this->proportional($line->money, $ratio);

                if ($account->subtype === 'tax_out') {
                    // R3: VAT income-relevant.
                    if ($inflow) {
                        $income = self::addTo($income, 'Vereinnahmte USt', $amount);
                    } else {
                        $expenses = self::addTo($expenses, 'USt-Zahlung an FA', $amount);
                    }
                } elseif ($account->subtype === 'tax_in') {
                    $expenses = self::addTo($expenses, 'Gezahlte Vorsteuer', $amount);
                } elseif ($account->type === AccountType::Revenue) {
                    $income = self::addTo($income, $this->label($mapping, $account), $amount);
                } elseif ($account->type === AccountType::Expense) {
                    $expenses = self::addTo($expenses, $this->label($mapping, $account), $amount);
                }
                // R4/R5: assets, loans, private, pass-through items — neutral.
            }
        }

        return [
            'income' => $this->serializeBucket($income),
            'expenses' => $this->serializeBucket($expenses),
        ];
    }


    /**
     * @param array<string, Money> $bucket
     *
     * @return array<string, Money>
     */
    private static function addTo(array $bucket, string $label, Money $amount): array
    {
        $bucket[$label] = isset($bucket[$label]) ? $bucket[$label]->add($amount) : $amount;

        return $bucket;
    }

    /** Cash-basis accounting is bound to the calendar year (§ 4 Abs. 3 EStG). */
    private function assertCalendarYearFiscalYears(int $year): void
    {
        $start = CalendarDate::of(sprintf('%04d-01-01', $year));
        $end = CalendarDate::of(sprintf('%04d-12-31', $year));

        foreach ($this->fiscalYears->all() as $fiscalYear) {
            $overlaps = !$fiscalYear->end->isBefore($start) && !$fiscalYear->start->isAfter($end);

            if (!$overlaps) {
                continue;
            }

            $isCalendarYear = substr($fiscalYear->start->iso, 5) === '01-01'
                && substr($fiscalYear->end->iso, 5) === '12-31';

            if (!$isCalendarYear) {
                throw new DomainError('E_CASHBASIS_DEVIATING_FISCAL_YEAR', sprintf(
                    'Fiscal year %d (%s to %s) deviates from the calendar year — cash-basis accounting is bound to the calendar year',
                    $fiscalYear->year,
                    $fiscalYear->start->iso,
                    $fiscalYear->end->iso,
                ), ['fiscalYear' => $fiscalYear->year]);
            }
        }
    }

    private function bankFlow(JournalEntry $entry): Money
    {
        $flow = Money::zero($this->baseCurrency);

        foreach ($entry->lines() as $line) {
            $account = $this->accounts->byId($line->accountId);
            // Money account := {bank, cash} (datenformat.md v0.4)
            if (!in_array($account?->subtype, ['bank', 'cash'], true)) {
                continue;
            }

            $flow = $line->side === Side::Debit ? $flow->add($line->money) : $flow->subtract($line->money);
        }

        return $flow;
    }

    /**
     * R2: payment year, unless the 10-day rule applies (paid AND due within
     * the window) — then the year of economic allocation.
     */
    private function assignYear(JournalEntry $entry): int
    {
        $voucher = $this->vouchers->byId($entry->voucherId);

        if (
            $voucher !== null
            && $voucher->recurring
            && $voucher->economicYear !== null
            && $voucher->due !== null
            && $this->inTenDayWindow($entry->entryDate)
            && $this->inTenDayWindow($voucher->due)
        ) {
            return $voucher->economicYear;
        }

        return $entry->entryDate->year();
    }

    private function inTenDayWindow(CalendarDate $date): bool
    {
        $month = $date->month();
        $day = (int) substr($date->iso, 8, 2);

        return ($month === 12 && $day >= 22) || ($month === 1 && $day <= 10);
    }

    /**
     * R1: source lines of the payment — on open-item settlement the lines of the
     * origin voucher, proportional to the settlement ratio; otherwise its own.
     *
     * @return list<array{line: EntryLine, ratio: BigDecimal}>
     */
    private function sourceLines(JournalEntry $entry): array
    {
        $sourced = [];

        foreach ($this->openItems->all() as $item) {
            foreach ($item->settlements() as $settlement) {
                if (!$settlement->entryId->equals($entry->id)) {
                    continue;
                }

                $origin = $this->journal->byId($item->originEntryId);
                if ($origin === null || $item->money->isZero()) {
                    continue;
                }

                $ratio = BigDecimal::of($settlement->money->amountAsString())
                    ->dividedBy(BigDecimal::of($item->money->amountAsString()), 10, RoundingMode::HALF_UP);

                foreach ($origin->lines() as $line) {
                    $sourced[] = ['line' => $line, 'ratio' => $ratio];
                }
            }
        }

        if ($sourced !== []) {
            return $sourced;
        }

        $one = BigDecimal::one();
        foreach ($entry->lines() as $line) {
            $sourced[] = ['line' => $line, 'ratio' => $one];
        }

        return $sourced;
    }

    private function proportional(Money $amount, BigDecimal $ratio): Money
    {
        if ($ratio->compareTo(BigDecimal::one()) === 0) {
            return $amount->abs();
        }

        return Money::fromCalculation(
            BigDecimal::of($amount->abs()->amountAsString())->multipliedBy($ratio),
            $this->baseCurrency,
        );
    }

    /** R6: Mapping-Label, sonst Kontoname. */
    private function label(?Mapping $mapping, Account $account): string
    {
        if ($mapping !== null) {
            $leaf = $mapping->leafFor($account->number->value);

            if ($leaf !== null) {
                return $leaf['label'];
            }
        }

        return $account->name;
    }

    /**
     * @param array<string, Money> $bucket
     *
     * @return list<array{category: string, amount: string}>
     */
    private function serializeBucket(array $bucket): array
    {
        $labels = array_map(strval(...), array_keys($bucket));
        usort($labels, static fn (string $a, string $b): int => strcmp($a, $b));

        $rows = [];
        foreach ($labels as $label) {
            if ($bucket[$label]->isZero()) {
                continue;
            }

            $rows[] = ['category' => $label, 'amount' => $bucket[$label]->amountAsString()];
        }

        return $rows;
    }
}
