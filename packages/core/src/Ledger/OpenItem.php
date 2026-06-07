<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Money;
use Rechnungswesen\Core\Shared\Uuid;

/**
 * Offener Posten (ledger-modell.md Aggregat 5): entsteht aus einer
 * Buchung auf ein AR/AP-Konto, referenziert Ursprungsbuchung + Position.
 * Invariante: Σ Ausgleiche ≤ Betrag; Teilausgleiche erlaubt.
 * Trägt die OP-Verknüpfung für die EÜR-Projektion (F-CORE-009).
 */
final class OpenItem implements \JsonSerializable
{
    /** @var list<Settlement> */
    private array $settlements = [];

    public function __construct(
        public readonly Uuid $id,
        public readonly OpenItemKind $kind,
        public readonly Uuid $originEntryId,
        public readonly int $originLineIndex,
        public readonly Money $money,
        public readonly Uuid $voucherId,
        public readonly CalendarDate $openedAt,
        public readonly ?Uuid $partnerId = null,
    ) {
    }

    /** @return list<Settlement> */
    public function settlements(): array
    {
        return $this->settlements;
    }

    public function remaining(): Money
    {
        return $this->remainingAt(null);
    }

    /** Restbetrag zum Stichtag (null = heute/alles). */
    public function remainingAt(?CalendarDate $asOf): Money
    {
        $remaining = $this->money;

        foreach ($this->settlements as $settlement) {
            if ($asOf !== null && $settlement->settledAt->isAfter($asOf)) {
                continue;
            }

            $remaining = $remaining->subtract($settlement->money);
        }

        return $remaining;
    }

    public function status(): OpenItemStatus
    {
        return $this->statusAt(null);
    }

    public function statusAt(?CalendarDate $asOf): OpenItemStatus
    {
        $remaining = $this->remainingAt($asOf);

        if ($remaining->isZero()) {
            return OpenItemStatus::Settled;
        }

        return $remaining->equals($this->money) ? OpenItemStatus::Open : OpenItemStatus::PartiallySettled;
    }

    public function settle(Settlement $settlement): void
    {
        if ($settlement->money->compareTo($this->remaining()) > 0) {
            throw new DomainError('E_SETTLEMENT_EXCEEDS_ITEM', sprintf(
                'Zuordnung %s übersteigt Restbetrag %s des Postens %s',
                $settlement->money->amountAsString(),
                $this->remaining()->amountAsString(),
                $this->id->value,
            ), [
                'openItemId' => $this->id->value,
                'remaining' => $this->remaining()->amountAsString(),
                'allocated' => $settlement->money->amountAsString(),
            ]);
        }

        $this->settlements[] = $settlement;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'kind' => $this->kind->value,
            'originEntryId' => $this->originEntryId->value,
            'originLineIndex' => $this->originLineIndex,
            'money' => $this->money->jsonSerialize(),
            'partnerId' => $this->partnerId?->value,
            'remaining' => $this->remaining()->jsonSerialize(),
            'status' => $this->status()->value,
            'settlements' => array_map(
                static fn (Settlement $settlement): array => $settlement->jsonSerialize(),
                $this->settlements,
            ),
        ];
    }
}
