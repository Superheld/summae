<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\Substrate\CalendarDate;

/**
 * Regelversion eines Steuerschlüssels mit Gültigkeitszeitraum (NF-5.1).
 * Inhalte sind Regelmodul-Daten — Code zitiert kein Gesetz.
 * mechanism `reverse_charge`: USt- und VSt-Position gleichzeitig,
 * je eigene Kennzahl; Zahlbetrag = Netto.
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
