<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

use Summae\Core\DomainError;
use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\FiscalYear;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Period;

/**
 * Fiscal years and periods: creating a year, closing and reopening a period, closing the year.
 * The constraint side of the ledger — these operations write no postings, they only decide what
 * may still be posted.
 */
final readonly class FiscalPeriodService
{
    /** 2^53-1 — the largest integer Node can represent exactly (Number.MAX_SAFE_INTEGER). */
    private const int MAX_EXACT_INT = 9007199254740991;

    public function __construct(
        private FiscalYearRepository $fiscalYears,
        private JournalRepository $journal,
        private IdGenerator $ids,
    ) {
    }

    /**
     * Create fiscal year (v0.4): overlap with existing years
     * is rejected (E_FISCALYEAR_OVERLAP); gaps are allowed.
     * Without explicit periods: 12 monthly periods.
     *
     * @param array<string, mixed> $input
     */
    public function createFiscalYear(array $input): FiscalYear
    {
        // Anything that was not an int became year 0 — a quoted "2027" from a JSON caller
        // created a fiscal year nobody could address again: every later report for 2027 came back
        // empty and correct-looking instead of saying the year does not exist. A fiscal year is a
        // positive whole number; 2028.5 or -5 are caller mistakes, not values to round into shape.
        // JSON knows no int/float split: `2027.0` arrives as a float here and as a plain number
        // in Node, so a whole-valued float counts as the same input in both languages.
        $rawYear = $input['year'] ?? null;

        // Bounded at 2^53-1 (Node's Number.isSafeInteger), not at PHP_INT_MAX: an int this side
        // can hold but Node cannot represent exactly would be accepted here and rejected there —
        // same input, different answer, which is the one thing the equivalence policy forbids.
        if (is_float($rawYear) && $rawYear === floor($rawYear) && abs($rawYear) <= self::MAX_EXACT_INT) {
            $rawYear = (int) $rawYear;
        }

        if (!is_int($rawYear) || $rawYear <= 0 || $rawYear > self::MAX_EXACT_INT) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'createFiscalYear requires "year" as a positive whole number',
                ['year' => DomainError::rejectedValue($rawYear)],
            );
        }

        $year = $rawYear;
        $start = Lookups::parseEntryDate($input['start'] ?? null);
        $end = Lookups::parseEntryDate($input['end'] ?? null);

        foreach ($this->fiscalYears->all() as $existing) {
            $overlaps = !$existing->end->isBefore($start) && !$existing->start->isAfter($end);

            if ($overlaps || $existing->year === $year) {
                throw new DomainError('E_FISCALYEAR_OVERLAP', sprintf(
                    'Fiscal year %d (%s to %s) overlaps with %d',
                    $year,
                    $start->iso,
                    $end->iso,
                    $existing->year,
                ), ['year' => $year, 'existing' => $existing->year]);
            }
        }

        $fiscalYear = FiscalYear::create($this->ids->next(), $year, $start, $end);
        $this->fiscalYears->add($fiscalYear);

        return $fiscalYear;
    }

    /** @param array<string, mixed> $input */
    public function closePeriod(array $input): Period
    {
        $fiscalYear = $this->requireFiscalYear($input['fiscalYear'] ?? null);
        $period = $fiscalYear->closePeriod($this->periodNumber($input));
        $this->fiscalYears->save($fiscalYear);

        return $period;
    }

    /** @param array<string, mixed> $input */
    public function reopenPeriod(array $input): Period
    {
        $fiscalYear = $this->requireFiscalYear($input['fiscalYear'] ?? null);
        $period = $fiscalYear->reopenPeriod($this->periodNumber($input));
        $this->fiscalYears->save($fiscalYear);

        return $period;
    }

    /**
     * Pure status change with preconditions: all periods closed,
     * all postings finalized (api.md v0.3) — NO closing entries.
     *
     * @param array<string, mixed> $input
     */
    public function closeFiscalYear(array $input): FiscalYear
    {
        $fiscalYear = $this->requireFiscalYear($input['fiscalYear'] ?? null);

        foreach ($this->journal->forFiscalYear($fiscalYear->year) as $entry) {
            if (!$entry->isFinalized()) {
                throw new DomainError('E_FISCALYEAR_UNFINALIZED_ENTRIES', sprintf(
                    'Year-end close %d: posting %d is not finalized',
                    $fiscalYear->year,
                    $entry->sequenceNumber,
                ), ['fiscalYear' => $fiscalYear->year, 'sequenceNumber' => $entry->sequenceNumber]);
            }
        }

        $fiscalYear->close();
        $this->fiscalYears->save($fiscalYear);

        return $fiscalYear;
    }

    private function requireFiscalYear(mixed $year): FiscalYear
    {
        $fiscalYear = is_int($year) ? $this->fiscalYears->byYear($year) : null;

        return $fiscalYear ?? throw new DomainError('E_PERIOD_UNKNOWN', sprintf(
            'Fiscal year %s is not created',
            is_int($year) ? (string) $year : '?',
        ));
    }

    /** @param array<string, mixed> $input */
    private function periodNumber(array $input): int
    {
        $period = $input['period'] ?? null;

        if (!is_int($period)) {
            throw new DomainError('E_PERIOD_UNKNOWN', 'Period number missing');
        }

        return $period;
    }
}
