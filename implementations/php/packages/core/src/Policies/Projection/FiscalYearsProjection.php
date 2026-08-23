<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Substrate\FiscalYear;
use Summae\Core\Substrate\Period;

/**
 * Fiscal years and their periods, with the status of each (F-CORE-029).
 *
 * `closePeriod`, `reopenPeriod` and `closeFiscalYear` decide what may still be posted, and until
 * this projection nothing on the read side said what they had decided. `systemDescription` names
 * the invariant — "a closed period accepts no further postings" — without naming the periods.
 *
 * What was left was `auditLog`, which records every close and reopen. That is a **trail, not a
 * state**: replaying it into "period 3 is open" makes the application rebuild library state from a
 * log, and it is wrong the moment a period is closed by anything that did not pass through that
 * application. The same shape as the account status — the write side owning state the read side
 * does not publish.
 *
 * `start` and `end` come out with it, and they are not decoration: without them an application
 * cannot offer a period *list* at all. Twelve months is a guess that a fiscal year running
 * February to January does not share, and a form that invents them asks for input the ledger will
 * refuse.
 *
 * Ordered by year, periods by their number — the order they fall due in.
 */
final readonly class FiscalYearsProjection
{
    public function __construct(
        private FiscalYearRepository $fiscalYears,
    ) {
    }

    /**
     * @param array<string, mixed> $params fiscalYear?
     *
     * @return array{fiscalYears: list<array<string, mixed>>}
     */
    public function compute(array $params): array
    {
        $wanted = Parameters::integerOrNull($params['fiscalYear'] ?? null);

        $years = array_values(array_filter(
            $this->fiscalYears->all(),
            static fn (FiscalYear $year): bool => $wanted === null || $year->year === $wanted,
        ));
        usort($years, static fn (FiscalYear $a, FiscalYear $b): int => $a->year <=> $b->year);

        $rows = [];
        foreach ($years as $year) {
            $periods = $year->periods();
            usort($periods, static fn (Period $a, Period $b): int => $a->number <=> $b->number);

            $rows[] = [
                'year' => $year->year,
                'start' => $year->start->iso,
                'end' => $year->end->iso,
                'status' => $year->status()->value,
                'periods' => array_map(static fn (Period $period): array => [
                    'period' => $period->number,
                    'start' => $period->start->iso,
                    'end' => $period->end->iso,
                    'status' => $period->status()->value,
                ], $periods),
            ];
        }

        return ['fiscalYears' => $rows];
    }
}
