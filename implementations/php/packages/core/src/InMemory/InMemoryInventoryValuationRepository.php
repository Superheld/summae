<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Policies\Expansion\Inventory\InventoryValuation;
use Summae\Core\Port\InventoryValuationRepository;

final class InMemoryInventoryValuationRepository implements InventoryValuationRepository
{
    /** @var list<InventoryValuation> */
    private array $valuations = [];

    public function add(InventoryValuation $valuation): void
    {
        $this->valuations[] = $valuation;
    }

    public function all(): array
    {
        $valuations = $this->valuations;
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
