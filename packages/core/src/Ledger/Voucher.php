<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Uuid;

/**
 * Beleg (ledger-modell.md Aggregat 4): existiert vor/ohne Buchung,
 * mehrere Buchungen können ihn referenzieren. Metadaten `due`,
 * `recurring`, `economicYear` braucht die EÜR-Projektion (R2/R5).
 */
final readonly class Voucher implements \JsonSerializable
{
    public function __construct(
        public Uuid $id,
        public string $voucherNumber,
        public CalendarDate $voucherDate,
        public ?CalendarDate $due = null,
        public bool $recurring = false,
        public ?int $economicYear = null,
        public ?string $supplierTaxationMethod = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'voucherNumber' => $this->voucherNumber,
            'voucherDate' => $this->voucherDate->iso,
            'due' => $this->due?->iso,
            'recurring' => $this->recurring,
            'economicYear' => $this->economicYear,
            'supplierTaxationMethod' => $this->supplierTaxationMethod,
        ];
    }
}
