<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\Substrate\Money;

/**
 * Exempt supply: tax-free — no tax line, base tagged for reporting. Mechanically like an
 * intra-community supply but a distinct mechanism, so projections that single out IC supplies
 * (the EC sales list) do not pick it up. Lets an exempt code post without a rejected 0.00 tax
 * line (the reason a plain rate-0 standard code could not — NF-004/F-010).
 */
final class ExemptMechanism implements TaxMechanism
{
    public function requiresInputTaxAccount(): bool
    {
        return false;
    }

    public function affectsEcSalesList(): bool
    {
        return false;
    }

    public function vatReturnDirection(): ?string
    {
        return null;
    }

    public function contribute(TaxCodeVersion $version, Money $tax, string $outputSide, \Closure $tag, Money $zero): array
    {
        return [
            'taxLines' => [],
            'baseTag' => $tag($version->reportingKey),
            'grossDelta' => $zero,
        ];
    }
}
