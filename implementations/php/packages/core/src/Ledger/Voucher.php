<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Uuid;

/**
 * Beleg (ledger-modell.md Aggregat 4): existiert vor/ohne Buchung,
 * mehrere Buchungen können ihn referenzieren.
 *
 * Metadaten: `due`/`recurring`/`economicYear` für die EÜR (R2);
 * `serviceDate`/`servicePeriod` (v0.4, § 27 UStG: Steuerregelversion und
 * Soll-VA folgen dem Leistungsdatum); `partnerId` (v0.4, vererbt an OPs);
 * `kind` als Auswertungs-/Exporthilfe ohne Kernlogik.
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
        public ?CalendarDate $serviceDate = null,
        public ?CalendarDate $servicePeriodFrom = null,
        public ?CalendarDate $servicePeriodTo = null,
        public ?string $kind = null,
        public ?Uuid $partnerId = null,
        public ?string $issuer = null,
    ) {
    }

    /** Steuerlich maßgebliches Datum: Leistungsdatum, Fallback Belegdatum. */
    public function taxDate(): CalendarDate
    {
        return $this->serviceDate ?? $this->servicePeriodTo ?? $this->voucherDate;
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
            'serviceDate' => $this->serviceDate?->iso,
            'servicePeriod' => $this->servicePeriodFrom === null ? null : [
                'from' => $this->servicePeriodFrom->iso,
                'to' => $this->servicePeriodTo?->iso,
            ],
            'kind' => $this->kind,
            'partnerId' => $this->partnerId?->value,
            'issuer' => $this->issuer,
        ];
    }
}
