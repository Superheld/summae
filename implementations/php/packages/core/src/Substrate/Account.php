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

    /**
     * The way back (F-CORE-033).
     *
     * The lock had no counterpart for a long time, and the question that decided this was whether the
     * irreversibility was *law*. It is not. What the German rules protect against unrecognisable
     * change are **postings**; for master data they ask that the change be *logged* — which the audit
     * trail does, in both directions. And that is the German answer to a question no other
     * jurisdiction answers differently either: no chart of accounts anywhere is a one-way door. So
     * nothing here is a jurisdiction's answer and nothing belongs in a pack. The sources are in the
     * knowledge base (`knowledge/10-fachwissen/17-gobd-compliance.md`), where a statute may be named.
     *
     * What a lock protects is the books, and it keeps doing that: an account cannot be posted to
     * while it is locked, and every posting ever made on it stays exactly where it is. Unlocking
     * changes nothing about the past — it only allows a future posting again, which is why a
     * mis-clicked lock does not have to be repaired by abandoning the account and opening a second
     * one under a new number.
     */
    public function unlock(): void
    {
        $this->status = AccountStatus::Active;
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
