<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Reference of a posting to its voucher (datenformat.md `voucherId`).
 * Every posting MUST reference a voucher (E_ENTRY_NO_VOUCHER) —
 * the dedicated type makes that unmissable in code.
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
