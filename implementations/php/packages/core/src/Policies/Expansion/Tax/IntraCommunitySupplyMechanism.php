<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\Substrate\Money;

/** Intra-community supply: tax-free — no tax line, just the reporting-key tag on the base. */
final class IntraCommunitySupplyMechanism implements TaxMechanism
{
    public function contribute(TaxCodeVersion $version, Money $tax, string $outputSide, \Closure $tag, Money $zero): array
    {
        return [
            'taxLines' => [],
            'baseTag' => $tag($version->reportingKey),
            'grossDelta' => $zero,
        ];
    }
}
