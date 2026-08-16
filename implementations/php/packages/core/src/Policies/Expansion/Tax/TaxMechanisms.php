<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

/**
 * Registry of the tax mechanisms (counterpart to the Node `mechanismFor` function).
 *
 * The repertoire is CLOSED (decided 2026-08-16): mechanisms are registered here, inside the core,
 * in both languages — never from outside. A mechanism plugged in by the embedder would be
 * different code in PHP than in Node, and "same input → same result regardless of language" is the
 * project's top rule; the shared fixtures could not check such a mechanism at all. The reasoning
 * and what would reopen the question: `core/src/CLAUDE.md`.
 *
 * The unknown-mechanism fallback is still lenient (any unregistered name falls back to the
 * standard mechanism, exactly as the old `else` branch in TaxService did), so a typo in a pack
 * books standard VAT silently. Tightening that to an error is a separate hardening, not done.
 */
final class TaxMechanisms
{
    public static function mechanismFor(string $name): TaxMechanism
    {
        return match ($name) {
            'reverse_charge' => new ReverseChargeMechanism(),
            'intra_community_supply' => new IntraCommunitySupplyMechanism(),
            'exempt' => new ExemptMechanism(),
            default => new StandardMechanism(),
        };
    }
}
