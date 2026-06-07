<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Shared;

/**
 * Verweis einer Buchung auf ihren Beleg (datenformat.md `voucherId`).
 * Jede Buchung MUSS einen Beleg referenzieren (E_ENTRY_NO_VOUCHER) —
 * der eigene Typ macht das im Code unübersehbar.
 */
final readonly class VoucherRef implements \JsonSerializable, \Stringable
{
    private function __construct(
        public Uuid $voucherId,
    ) {
    }

    public static function of(Uuid|string $voucherId): self
    {
        return new self($voucherId instanceof Uuid ? $voucherId : Uuid::fromString($voucherId));
    }

    public function equals(self $other): bool
    {
        return $this->voucherId->equals($other->voucherId);
    }

    public function jsonSerialize(): string
    {
        return $this->voucherId->value;
    }

    public function __toString(): string
    {
        return $this->voucherId->value;
    }
}
