<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\Substrate\CalendarDate;

/**
 * Rule version of a tax code with validity period (NF-5.1).
 * Contents are rule-module data — code cites no statute.
 * mechanism `reverse_charge`: VAT and input-tax line at once,
 * each with its own reporting key; payable = net.
 */
final readonly class TaxCodeVersion
{
    public function __construct(
        public CalendarDate $validFrom,
        public ?CalendarDate $validTo,
        public string $rate,
        public string $taxAccount,
        public ?string $reportingKey,
        public string $mechanism = 'standard',
        public ?string $inputTaxAccount = null,
        public ?string $inputReportingKey = null,
        public ?string $baseReportingKey = null,
    ) {
    }

    public function coversDate(CalendarDate $date): bool
    {
        if ($date->isBefore($this->validFrom)) {
            return false;
        }

        return $this->validTo === null || !$date->isAfter($this->validTo);
    }
}
