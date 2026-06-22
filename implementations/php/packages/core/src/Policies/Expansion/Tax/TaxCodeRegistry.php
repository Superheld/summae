<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\DomainError;
use Summae\Core\Substrate\CalendarDate;

/**
 * Geladene, validierte Form der Steuerschlüssel-Regelmodul-Daten.
 */
final readonly class TaxCodeRegistry
{
    /**
     * @param array<string, TaxCode> $codes
     */
    private function __construct(
        private array $codes,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<array<mixed>> $data Regelmodul-Daten (setup.taxCodes)
     */
    public static function fromData(array $data): self
    {
        $codes = [];

        foreach ($data as $codeData) {
            $code = is_string($codeData['code'] ?? null) ? $codeData['code'] : '';
            $versions = [];

            foreach (is_array($codeData['versions'] ?? null) ? $codeData['versions'] : [] as $versionData) {
                if (!is_array($versionData)) {
                    continue;
                }

                $versions[] = new TaxCodeVersion(
                    CalendarDate::of(is_string($versionData['validFrom'] ?? null) ? $versionData['validFrom'] : ''),
                    is_string($versionData['validTo'] ?? null) ? CalendarDate::of($versionData['validTo']) : null,
                    is_string($versionData['rate'] ?? null) ? $versionData['rate'] : '0',
                    is_string($versionData['taxAccount'] ?? null) ? $versionData['taxAccount'] : '',
                    is_string($versionData['reportingKey'] ?? null) ? $versionData['reportingKey'] : null,
                    is_string($versionData['mechanism'] ?? null) ? $versionData['mechanism'] : 'standard',
                    is_string($versionData['inputTaxAccount'] ?? null) ? $versionData['inputTaxAccount'] : null,
                    is_string($versionData['inputReportingKey'] ?? null) ? $versionData['inputReportingKey'] : null,
                    is_string($versionData['baseReportingKey'] ?? null) ? $versionData['baseReportingKey'] : null,
                );
            }

            $codes[$code] = new TaxCode(
                $code,
                $versions,
                is_string($codeData['datevBu'] ?? null) ? $codeData['datevBu'] : null,
            );
        }

        return new self($codes);
    }

    /** @return list<TaxCodeVersion> alle Versionen aller Schlüssel */
    public function allVersions(): array
    {
        $versions = [];

        foreach ($this->codes as $code) {
            foreach ($code->versions as $version) {
                $versions[] = $version;
            }
        }

        return $versions;
    }

    /** DATEV-BU-Alias eines Schlüssels (eigene Codes bleiben führend). */
    public function datevBuFor(string $code): ?string
    {
        return ($this->codes[$code] ?? null)?->datevBu;
    }

    public function get(string $code): TaxCode
    {
        return $this->codes[$code] ?? throw new DomainError('E_TAXCODE_UNKNOWN', sprintf(
            'Steuerschlüssel "%s" ist nicht definiert',
            $code,
        ), ['code' => $code]);
    }

    public function versionFor(string $code, CalendarDate $date): TaxCodeVersion
    {
        return $this->get($code)->versionFor($date);
    }
}
