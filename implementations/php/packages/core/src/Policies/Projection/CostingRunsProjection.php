<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\CostingRunRepository;

/**
 * Which costing runs exist (F-KLR-001).
 *
 * `costAllocationSheet`, `overheadRates` and `productionCost` all require a `runId`, and until this
 * existed no projection answered where one comes from: the only way to hold a valid id was to have
 * kept the one `runCosting` returned. An embedding therefore had to keep its own table of run ids
 * beside the books — a second register of library state, which is the arrangement F-13 already
 * named as a bug waiting for the first change that does not pass through the screen that writes it.
 *
 * The repository has sorted `all()` since the runs were persisted, so this is a published-surface
 * gap and not a design boundary: the data was there and the way to it was not.
 *
 * Deliberately thin. It reports what a run *is* — id, period, version, status, method — and nothing
 * computed from one; the three projections above are where a run's figures live, and duplicating a
 * total here would create a second answer to a question that already has one.
 */
final readonly class CostingRunsProjection
{
    public function __construct(private CostingRunRepository $runs)
    {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{runs: list<array{runId: string, fiscalYear: int, period: int, version: int, status: string, method: string}>}
     */
    public function compute(array $params): array
    {
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : null;
        $period = is_int($params['period'] ?? null) ? $params['period'] : null;

        // all() is already sorted by period, then version — the order a reader of a period's history
        // wants, and the order the next version number comes from. Filtering keeps it.
        $rows = [];
        foreach ($this->runs->all() as $run) {
            if ($fiscalYear !== null && $run->period->fiscalYear !== $fiscalYear) {
                continue;
            }
            if ($period !== null && $run->period->period !== $period) {
                continue;
            }

            $rows[] = [
                'runId' => $run->id->value,
                'fiscalYear' => $run->period->fiscalYear,
                'period' => $run->period->period,
                'version' => $run->version,
                'status' => $run->status(),
                'method' => $run->method,
            ];
        }

        return ['runs' => $rows];
    }
}
