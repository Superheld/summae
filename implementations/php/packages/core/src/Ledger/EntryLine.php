<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

use Rechnungswesen\Core\Shared\AccountNumber;
use Rechnungswesen\Core\Shared\DimensionValue;
use Rechnungswesen\Core\Shared\Money;
use Rechnungswesen\Core\Shared\Uuid;

/**
 * Buchungsposition — Value Object innerhalb der Buchung
 * (keine eigene Identität; Referenz = Buchungs-ID + Positionsindex).
 */
final readonly class EntryLine implements \JsonSerializable
{
    /**
     * @param list<DimensionValue> $dimensions
     * @param array<string, mixed>|null $taxTag taxTag laut datenformat.md (VO folgt mit JOB-006)
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
