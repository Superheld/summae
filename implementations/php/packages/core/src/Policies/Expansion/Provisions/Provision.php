<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Provisions;

use Summae\Core\DomainError;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * A provision, with its history (F-CORE-051).
 *
 * **Why this is an aggregate and not just a balance.** The balance of a provision account answers
 * almost nothing an auditor asks. What was set aside, for what, when, at what estimate — and then
 * what happened to it: was it *used* because the obligation materialised, *released* because the
 * reason ceased, or *re-measured* because the estimate moved? Those are three different events with
 * three different postings and three different meanings, and a netted balance shows none of them.
 * Same reason the asset register exists next to the asset accounts.
 *
 * **The movement list is the point, and it is append-only.** Every step names the entry it produced,
 * so the register and the journal can be walked against each other in both directions. Nothing here
 * is ever edited: a re-measurement is a new movement, not a corrected old one.
 *
 * **`settled` is not `released`.** A provision reaches `settled` when its carrying amount is zero,
 * however it got there. *How* it got there is in the movements, and the difference matters: a
 * release is income the business never had to pay, a use is an obligation that came true. A status
 * field that collapsed the two would be the same kind of lie as a netted balance.
 */
final class Provision implements \JsonSerializable
{
    /**
     * @var list<array{kind: string, date: CalendarDate, amount: Money, entryId: Uuid|null, note: string|null}>
     */
    private array $movements = [];

    private Money $carryingAmount;

    public function __construct(
        public readonly Uuid $id,
        public readonly string $reason,
        public readonly AccountNumber $account,
        public readonly AccountNumber $expenseAccount,
        public readonly AccountNumber $releaseAccount,
        public readonly CalendarDate $recognizedOn,
        public readonly ?CalendarDate $dueDate,
        /** The undiscounted best estimate of the amount needed to settle (the *Erfüllungsbetrag*). */
        public readonly Money $settlementAmount,
        /** What was actually recognised — the present value where discounting applied. */
        Money $recognizedAmount,
        /** The rate the discount used, as a percentage string, or null where nothing was discounted. */
        public readonly ?string $discountRate,
    ) {
        $this->carryingAmount = $recognizedAmount;
    }

    public function carryingAmount(): Money
    {
        return $this->carryingAmount;
    }

    public function isSettled(): bool
    {
        return $this->carryingAmount->isZero();
    }

    public function status(): string
    {
        return $this->isSettled() ? 'settled' : 'open';
    }

    /** @return list<array{kind: string, date: CalendarDate, amount: Money, entryId: Uuid|null, note: string|null}> */
    public function movements(): array
    {
        return $this->movements;
    }

    public function record(string $kind, CalendarDate $date, Money $amount, ?Uuid $entryId, ?string $note = null): void
    {
        $this->movements[] = [
            'kind' => $kind,
            'date' => $date,
            'amount' => $amount,
            'entryId' => $entryId,
            'note' => $note,
        ];
    }

    /**
     * Move the carrying amount. Never below zero — a provision that has given back more than it
     * held would be income invented out of a sign error, and the operations that call this each
     * cap their own amount, so reaching here is a bug rather than a user mistake.
     */
    public function moveCarryingAmount(Money $delta): void
    {
        $next = $this->carryingAmount->add($delta);

        if ($next->isNegative()) {
            throw new DomainError('E_PROVISION_EXCEEDS_CARRYING', sprintf(
                'provision %s carries %s — %s cannot be taken from it',
                $this->id->value,
                $this->carryingAmount->amountAsString(),
                $delta->abs()->amountAsString(),
            ), ['provisionId' => $this->id->value, 'carryingAmount' => $this->carryingAmount->amountAsString()]);
        }

        $this->carryingAmount = $next;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $movements = [];
        foreach ($this->movements as $movement) {
            $movements[] = [
                'kind' => $movement['kind'],
                'date' => $movement['date']->iso,
                'amount' => $movement['amount']->jsonSerialize(),
                'entryId' => $movement['entryId']?->value,
                'note' => $movement['note'],
            ];
        }

        return [
            'id' => $this->id->value,
            'reason' => $this->reason,
            'account' => $this->account->value,
            'expenseAccount' => $this->expenseAccount->value,
            'releaseAccount' => $this->releaseAccount->value,
            'recognizedOn' => $this->recognizedOn->iso,
            'dueDate' => $this->dueDate?->iso,
            'settlementAmount' => $this->settlementAmount->jsonSerialize(),
            'carryingAmount' => $this->carryingAmount->jsonSerialize(),
            'discountRate' => $this->discountRate,
            'movements' => $movements,
        ];
    }

    /**
     * Restore from persistence — the carrying amount and the movements are taken over directly, no
     * replay. Replaying them would recompute a history that is already a fact.
     *
     * @param list<array{kind: string, date: CalendarDate, amount: Money, entryId: Uuid|null, note: string|null}> $movements
     */
    public static function restore(
        Uuid $id,
        string $reason,
        AccountNumber $account,
        AccountNumber $expenseAccount,
        AccountNumber $releaseAccount,
        CalendarDate $recognizedOn,
        ?CalendarDate $dueDate,
        Money $settlementAmount,
        Money $carryingAmount,
        ?string $discountRate,
        array $movements,
    ): self {
        $provision = new self(
            $id,
            $reason,
            $account,
            $expenseAccount,
            $releaseAccount,
            $recognizedOn,
            $dueDate,
            $settlementAmount,
            $carryingAmount,
            $discountRate,
        );
        $provision->movements = $movements;

        return $provision;
    }
}
