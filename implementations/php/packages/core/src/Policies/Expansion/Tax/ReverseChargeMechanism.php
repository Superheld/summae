<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\Substrate\Money;

/** Reverse charge: VAT and input tax at once (credit + debit), each its own key; payable = net. */
final class ReverseChargeMechanism implements TaxMechanism
{
    public function contribute(TaxCodeVersion $version, Money $tax, string $outputSide, \Closure $tag, Money $zero): array
    {
        return [
            'taxLines' => [
                [
                    'account' => $version->taxAccount,
                    'side' => 'credit',
                    'money' => $tax->jsonSerialize(),
                    'taxTag' => $tag($version->reportingKey),
                ],
                [
                    'account' => $version->inputTaxAccount ?? $version->taxAccount,
                    'side' => 'debit',
                    'money' => $tax->jsonSerialize(),
                    'taxTag' => $tag($version->inputReportingKey),
                ],
            ],
            'baseTag' => $tag($version->baseReportingKey ?? $version->reportingKey),
            'grossDelta' => $zero,
        ];
    }
}
