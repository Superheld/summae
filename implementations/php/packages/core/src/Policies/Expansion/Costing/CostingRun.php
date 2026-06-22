<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Costing;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;

/**
 * Abrechnungslauf (costing-modell.md Aggregat 1): je Periode + Version
 * eindeutig; Wiederholung erzeugt neue Version. draft -> released.
 * Invarianten: Verrechnungssumme bleibt erhalten, Hilfsstellen nach
 * Umlage = 0 (vom Service beim Rechnen sichergestellt).
 */
final class CostingRun
{
    private string $status = 'draft';

    /**
     * @param array<string, Money> $primary Kostenstelle -> Primärkosten
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
                'Lauf %s ist bereits freigegeben — Änderungen erzeugen eine neue Version',
                $this->id->value,
            ), ['runId' => $this->id->value]);
        }

        $this->status = 'released';
    }
}
