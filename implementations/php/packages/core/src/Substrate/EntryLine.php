<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\DimensionValue;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Posting line — value object within the posting
 * (no own identity; reference = posting ID + line index).
 */
final readonly class EntryLine implements \JsonSerializable
{
    /**
     * @param list<DimensionValue> $dimensions
     * @param array<string, mixed>|null $taxTag taxTag per datenformat.md (VO follows with JOB-006)
     */
    public function __construct(
        public Uuid $accountId,
        public AccountNumber $account,
        public Side $side,
        public Money $money,
        public array $dimensions = [],
        public ?array $taxTag = null,
    ) {
    }

    public function negated(): self
    {
        return new self(
            $this->accountId,
            $this->account,
            $this->side,
            $this->money->negate(),
            $this->dimensions,
            $this->taxTag,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'accountId' => $this->accountId->value,
            'account' => $this->account->value,
            'side' => $this->side->value,
            'money' => $this->money->jsonSerialize(),
            'dimensions' => array_map(
                static fn (DimensionValue $dimension): array => $dimension->jsonSerialize(),
                $this->dimensions,
            ),
            'taxTag' => $this->taxTag,
        ];
    }
}
