<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Policies\Expansion\Inventory\InventoryValuation;
use Summae\Core\Port\InventoryValuationRepository;
use Summae\Core\Substrate\Uuid;

final class InMemoryInventoryValuationRepository implements InventoryValuationRepository
{
    /** @var array<string, InventoryValuation> */
    private array $byId = [];

    public function add(InventoryValuation $valuation): void
    {
        $this->byId[$valuation->id->value] = $valuation;
    }

    public function byId(Uuid $id): ?InventoryValuation
    {
        return $this->byId[$id->value] ?? null;
    }

    public function all(): array
    {
        $valuations = array_values($this->byId);
        usort($valuations, static function (InventoryValuation $a, InventoryValuation $b): int {
            $byYear = $a->period->fiscalYear <=> $b->period->fiscalYear;
            if ($byYear !== 0) {
                return $byYear;
            }
            $byPeriod = $a->period->period <=> $b->period->period;

            return $byPeriod !== 0 ? $byPeriod : $a->version <=> $b->version;
        });

        return $valuations;
    }
}
