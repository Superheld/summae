<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion;

use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\SettlementDifferenceKind;

/**
 * Einzelner Ausgleich eines offenen Postens. `money` ist der
 * ausgeglichene OP-Betrag EINSCHLIESSLICH Differenz (api.md G2).
 */
final readonly class Settlement implements \JsonSerializable
{
    public function __construct(
        public Uuid $entryId,
        public Money $money,
        public CalendarDate $settledAt,
        public ?Money $differenceMoney = null,
        public ?SettlementDifferenceKind $differenceKind = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'entryId' => $this->entryId->value,
            'money' => $this->money->jsonSerialize(),
            'settledAt' => $this->settledAt->iso,
            'difference' => $this->differenceMoney === null ? null : [
                'money' => $this->differenceMoney->jsonSerialize(),
                'kind' => $this->differenceKind?->value,
            ],
        ];
    }
}
