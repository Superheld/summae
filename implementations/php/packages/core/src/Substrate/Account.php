<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Uuid;

/**
 * Account (ledger-modell.md aggregate 2). No balance in the aggregate —
 * balances are projections of the journal, always.
 */
final class Account implements \JsonSerializable
{
    public function __construct(
        public readonly Uuid $id,
        public readonly AccountNumber $number,
        public readonly string $name,
        public readonly AccountType $type,
        public readonly ?string $subtype,
        private AccountStatus $status = AccountStatus::Active,
    ) {
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function isLocked(): bool
    {
        return $this->status === AccountStatus::Locked;
    }

    public function lock(): void
    {
        $this->status = AccountStatus::Locked;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'number' => $this->number->value,
            'name' => $this->name,
            'type' => $this->type->value,
            'subtype' => $this->subtype,
            'status' => $this->status->value,
        ];
    }
}
