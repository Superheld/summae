<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Inventory;

use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;

/**
 * One act of valuing stock, recorded (F-CORE-050).
 *
 * **What this is not, first, because that is the whole design.** It is not a stock ledger. summae
 * does not know what is in the warehouse and never claims to: quantities arrive as *input* to a
 * valuation and are not carried forward, there are no goods movements, no bills of material and no
 * product master. Those are the embedding application's data.
 *
 * **What it is** is the same thing `Asset` is one layer over: summae does not own the machine, it
 * owns the register and the postings. Here it owns the *act of valuing* — which accounts, which
 * quantities, at what unit value, where that value came from, what the comparison with a market
 * value did, and which entry it produced. Keeping that is not optional bookkeeping tidiness: an
 * engine that posts a change in stock and keeps no record of how it reached the number has done
 * exactly what this project refuses to let an embedder do, one level down.
 *
 * **Versioned per period, like a costing run, and for the same reason.** Repetition creates a new
 * version rather than overwriting one. The posting is always the *difference* to what the accounts
 * already carry, so a second valuation of an unchanged period books nothing and records that it
 * booked nothing (`entryId: null`) — self-correcting rather than duplicating, without an
 * idempotency key to get wrong.
 *
 * Every figure is a string at the currency's scale. The quantity is a string too: it is not Money,
 * it is not rounded, and it must survive a round trip through JSON byte-identically in both
 * languages.
 */
final readonly class InventoryValuation
{
    /**
     * @param list<array{
     *     account: string,
     *     quantity: string,
     *     unitCost: string,
     *     marketValue: string|null,
     *     unitValue: string,
     *     source: string,
     *     openingValue: string,
     *     closingValue: string,
     *     change: string,
     *     changeAccount: string,
     *     writtenDownToMarket: bool
     * }> $categories
     */
    public function __construct(
        public Uuid $id,
        public PeriodRef $period,
        public int $version,
        public CalendarDate $valuationDate,
        public ?Uuid $runId,
        public array $categories,
        public Money $closingTotal,
        public Money $change,
        public ?Uuid $entryId,
    ) {
    }

    /**
     * Persistable form.
     *
     * Frozen, like a released costing run: the categories carry the unit values the act used, not
     * the ones the configuration would produce today. A valuation that answers differently
     * tomorrow is not a valuation of anything.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'period' => $this->period->jsonSerialize(),
            'version' => $this->version,
            'valuationDate' => $this->valuationDate->iso,
            'runId' => $this->runId?->value,
            'categories' => $this->categories,
            'closingTotal' => $this->closingTotal->jsonSerialize(),
            'change' => $this->change->jsonSerialize(),
            'entryId' => $this->entryId?->value,
        ];
    }

    /**
     * @param list<array{
     *     account: string,
     *     quantity: string,
     *     unitCost: string,
     *     marketValue: string|null,
     *     unitValue: string,
     *     source: string,
     *     openingValue: string,
     *     closingValue: string,
     *     change: string,
     *     changeAccount: string,
     *     writtenDownToMarket: bool
     * }> $categories
     */
    public static function restore(
        Uuid $id,
        PeriodRef $period,
        int $version,
        CalendarDate $valuationDate,
        ?Uuid $runId,
        array $categories,
        Money $closingTotal,
        Money $change,
        ?Uuid $entryId,
    ): self {
        return new self($id, $period, $version, $valuationDate, $runId, $categories, $closingTotal, $change, $entryId);
    }
}
