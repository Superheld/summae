<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Policies\Expansion\Costing\CostingRun;
use Summae\Core\Port\CostingRunRepository;
use Summae\Core\Substrate\Uuid;

/** Costing runs, in memory — the fake behind CostingRunRepository. */
final class InMemoryCostingRunRepository implements CostingRunRepository
{
    /** @var array<string, CostingRun> */
    private array $byId = [];

    public function add(CostingRun $run): void
    {
        $this->byId[$run->id->value] = $run;
    }

    public function save(CostingRun $run): void
    {
    }

    public function byId(Uuid $id): ?CostingRun
    {
        return $this->byId[$id->value] ?? null;
    }

    public function all(): array
    {
        $runs = array_values($this->byId);
        usort($runs, static function (CostingRun $a, CostingRun $b): int {
            $byYear = $a->period->fiscalYear <=> $b->period->fiscalYear;
            if ($byYear !== 0) {
                return $byYear;
            }
            $byPeriod = $a->period->period <=> $b->period->period;

            return $byPeriod !== 0 ? $byPeriod : $a->version <=> $b->version;
        });

        return $runs;
    }
}
