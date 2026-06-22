<?php

declare(strict_types=1);

namespace Summae\Core\Records;

use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Uuid;

/**
 * Voucher (ledger-modell.md aggregate 4): exists before/without a posting,
 * several postings can reference it.
 *
 * Metadata: `due`/`recurring`/`economicYear` for the EÜR (R2);
 * `serviceDate`/`servicePeriod` (v0.4, § 27 UStG: tax-rule version and
 * accrual-basis VAT follow the service date); `partnerId` (v0.4, inherited by OPs);
 * `kind` as an analysis/export hint without core logic.
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

    /** Tax-relevant date: service date, fallback voucher date. */
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
