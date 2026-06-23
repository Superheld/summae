<?php

declare(strict_types=1);

namespace Summae\Core\Records;

use Summae\Core\DomainError;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\OpenItemKind;
use Summae\Core\Substrate\OpenItemStatus;
use Summae\Core\Policies\Expansion\Settlement;

/**
 * Open item (ledger-modell.md aggregate 5): arises from a
 * posting to an AR/AP account, references the origin posting + line.
 * Invariant: Σ settlements ≤ amount; partial settlements allowed.
 * Carries the OP link for the cash-basis projection (F-CORE-009).
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

    /**
     * Rehydration from persistence (adapter).
     *
     * @param list<Settlement> $settlements
     */
    public static function restore(
        Uuid $id,
        OpenItemKind $kind,
        Uuid $originEntryId,
        int $originLineIndex,
        Money $money,
        Uuid $voucherId,
        CalendarDate $openedAt,
        ?Uuid $partnerId,
        array $settlements,
    ): self {
        $item = new self($id, $kind, $originEntryId, $originLineIndex, $money, $voucherId, $openedAt, $partnerId);
        $item->settlements = $settlements;

        return $item;
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

    /** Remaining amount as of a cutoff date (null = today/all). */
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
                'Allocation %s exceeds remaining amount %s of item %s',
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
