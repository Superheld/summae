<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Costing;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;

/**
 * Costing run (costing-modell.md aggregate 1): unique per period + version;
 * repetition creates a new version. draft -> released.
 * Invariants: the allocation total is preserved, auxiliary centers after
 * allocation = 0 (ensured by the service during computation).
 */
final class CostingRun
{
    private string $status = 'draft';

    /**
     * @param array<string, Money> $primary cost center -> primary costs
     * @param array<string, Money> $afterAllocation
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PeriodRef $period,
        public readonly int $version,
        public readonly array $primary,
        public readonly array $afterAllocation,
        public readonly Money $grandTotal,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function release(): void
    {
        if ($this->status === 'released') {
            throw new DomainError('E_COSTING_RUN_RELEASED', sprintf(
                'run %s is already released — changes create a new version',
                $this->id->value,
            ), ['runId' => $this->id->value]);
        }

        $this->status = 'released';
    }
}
