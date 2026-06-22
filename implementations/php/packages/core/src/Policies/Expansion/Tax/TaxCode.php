<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\DomainError;
use Summae\Core\Substrate\CalendarDate;

/**
 * Tax code (tax-modell.md aggregate 1): bundled tax case as a list
 * of rule versions. Version selection follows the voucher date.
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
            'tax code %s has no rule version valid for %s',
            $this->code,
            $date->iso,
        ), ['code' => $this->code, 'date' => $date->iso]);
    }
}
