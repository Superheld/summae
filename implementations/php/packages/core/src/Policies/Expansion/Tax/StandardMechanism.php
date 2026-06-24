<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\Substrate\Money;

/** Standard VAT: one tax line on the output/input side; gross = net + tax. */
final class StandardMechanism implements TaxMechanism
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
            'taxLines' => [[
                'account' => $version->taxAccount,
                'side' => $outputSide,
                'money' => $tax->jsonSerialize(),
                'taxTag' => $tag($version->reportingKey),
            ]],
            'baseTag' => $tag($version->reportingKey),
            'grossDelta' => $tax,
        ];
    }
}
