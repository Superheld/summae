<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion;

use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\SettlementCause;
use Summae\Core\Substrate\SettlementDifferenceKind;

/**
 * Single settlement of an open item. `money` is the settled
 * open-item amount INCLUDING the difference (api.md G2).
 *
 * `cause` says what closed the item, `difference` says by how much the amounts differed — the two
 * are independent: a payment can carry a discount, a cancellation never does.
 */
final readonly class Settlement implements \JsonSerializable
{
    public function __construct(
        public Uuid $entryId,
        public Money $money,
        public CalendarDate $settledAt,
        public ?Money $differenceMoney = null,
        public ?SettlementDifferenceKind $differenceKind = null,
        public SettlementCause $cause = SettlementCause::Payment,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'entryId' => $this->entryId->value,
            'money' => $this->money->jsonSerialize(),
            'settledAt' => $this->settledAt->iso,
            'cause' => $this->cause->value,
            'difference' => $this->differenceMoney === null ? null : [
                'money' => $this->differenceMoney->jsonSerialize(),
                'kind' => $this->differenceKind?->value,
            ],
        ];
    }
}
