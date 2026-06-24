<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

/**
 * Registry of the tax mechanisms (counterpart to the Node `mechanismFor` function). Core-internal
 * and lenient: any unregistered mechanism name falls back to the standard mechanism, exactly as
 * the old `else` branch in TaxService did. Whether composition may register further mechanisms
 * from outside the core is the still-open closed/open decision; the seam is here.
 */
final class TaxMechanisms
{
    public static function mechanismFor(string $name): TaxMechanism
    {
        return match ($name) {
            'reverse_charge' => new ReverseChargeMechanism(),
            'intra_community_supply' => new IntraCommunitySupplyMechanism(),
            default => new StandardMechanism(),
        };
    }
}
