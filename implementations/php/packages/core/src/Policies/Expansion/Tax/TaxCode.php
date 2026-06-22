<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\DomainError;
use Summae\Core\Substrate\CalendarDate;

/**
 * Steuerschlüssel (tax-modell.md Aggregat 1): gebündelter
 * Steuersachverhalt als Liste von Regelversionen. Die Versionswahl
 * folgt dem Belegdatum.
 */
final readonly class TaxCode
{
    /**
     * @param list<TaxCodeVersion> $versions
     */
    public function __construct(
        public string $code,
        public array $versions,
        public ?string $datevBu = null,
    ) {
    }

    public function versionFor(CalendarDate $date): TaxCodeVersion
    {
        foreach ($this->versions as $version) {
            if ($version->coversDate($date)) {
                return $version;
            }
        }

        throw new DomainError('E_TAXCODE_NO_VALID_VERSION', sprintf(
            'Steuerschlüssel %s hat keine zum %s gültige Regelversion',
            $this->code,
            $date->iso,
        ), ['code' => $this->code, 'date' => $date->iso]);
    }
}
