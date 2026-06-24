<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\Substrate\Money;

/**
 * Tax mechanism = the law-free strategy that turns one tax code's net base into tax line(s),
 * a base tag, and a gross delta. The repertoire used to be an inline switch in TaxService
 * (`reverse_charge` / `intra_community_supply` / else); it is now an addressable registry —
 * the "socket" the architecture calls for (substrate -> policy kinds -> pack). Each mechanism is
 * a small strategy here in the policy layer; the pack only *selects* one per tax code via
 * `version->mechanism`, it carries no code.
 *
 * This is the FORM (switch -> registry), byte-identical to the old branches. It does NOT decide
 * the open question (whether composition may register additional mechanisms from outside the
 * core): the registry is core-internal and the unknown-mechanism fallback stays lenient
 * (TaxMechanisms::mechanismFor returns the standard mechanism for any unrecognized name, exactly
 * as the old `else` branch did). Tightening that to strict is part of the open closed/open decision.
 */
interface TaxMechanism
{
    /** The resolver must check `inputTaxAccount` exists for this mechanism (reverse charge). */
    public function requiresInputTaxAccount(): bool;

    /** This mechanism's reporting keys feed the EC sales list (intra-community supply). */
    public function affectsEcSalesList(): bool;

    /** Fixed VAT-return direction, or `null` to derive it from the tax account. */
    public function vatReturnDirection(): ?string;

    /**
     * @param \Closure(string|null): array<string, mixed> $tag builds a tax tag for this
     *                                                          code/version/base with the given key
     *
     * @return array{taxLines: list<array<string, mixed>>, baseTag: array<string, mixed>, grossDelta: Money}
     */
    public function contribute(TaxCodeVersion $version, Money $tax, string $outputSide, \Closure $tag, Money $zero): array;
}
