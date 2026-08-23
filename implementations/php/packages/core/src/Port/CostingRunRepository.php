<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Policies\Expansion\Costing\CostingRun;
use Summae\Core\Substrate\Uuid;

/**
 * Costing runs (F-KLR-001/004).
 *
 * The one repository that was missing for years, and its absence was not neutral: the service kept
 * its runs in a private array, so a released run — the thing the requirements say evaluations read —
 * was gone with the process that produced it. `all()` is what gives a period its next version
 * number, which is also why the version no longer restarts at 1 after a restart.
 */
interface CostingRunRepository
{
    public function add(CostingRun $run): void;

    public function save(CostingRun $run): void;

    public function byId(Uuid $id): ?CostingRun;

    /** @return list<CostingRun> sorted by period, then version */
    public function all(): array;
}
