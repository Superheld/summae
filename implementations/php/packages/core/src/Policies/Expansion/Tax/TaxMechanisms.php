<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\DomainError;

/**
 * Registry of the tax mechanisms (counterpart to the Node `mechanismFor` function).
 *
 * The repertoire is CLOSED (decided 2026-08-16): mechanisms are registered here, inside the core,
 * in both languages — never from outside. A mechanism plugged in by the embedder would be
 * different code in PHP than in Node, and "same input → same result regardless of language" is the
 * project's top rule; the shared fixtures could not check such a mechanism at all. The reasoning
 * and what would reopen the question: `core/src/CLAUDE.md`.
 *
 * An unregistered name is refused, not quietly standardised. Because the repertoire is closed, a
 * name that is not listed here is a typo or a pack built against a newer core — and both used to
 * book plain VAT without a word: `reverse_charge` misspelled as `reverse-charge` produced a normal
 * tax line, on the normal account, in the normal VAT return box, and nothing in the output said
 * the mechanism had been dropped.
 */
final class TaxMechanisms
{
    /**
     * The registered repertoire, in registration order. Published because a *document* that names
     * the mechanisms — docs/gobd-conformance.md does — is making a checkable claim, and a claim
     * nothing checks is how that census came to describe a pack that had already moved on.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return ['standard', 'reverse_charge', 'intra_community_supply', 'exempt'];
    }

    public static function mechanismFor(string $name): TaxMechanism
    {
        return match ($name) {
            'standard' => new StandardMechanism(),
            'reverse_charge' => new ReverseChargeMechanism(),
            'intra_community_supply' => new IntraCommunitySupplyMechanism(),
            'exempt' => new ExemptMechanism(),
            // E_PACK_INCOHERENT because that is what it is: the modules resolve, but the bundle
            // asks for a mechanism that does not exist. The resolver calls this too, so a composed
            // pack fails at resolvePack/init rather than at the first posting.
            default => throw new DomainError('E_PACK_INCOHERENT', sprintf('Unknown tax mechanism: %s', $name), [
                'mechanism' => $name,
                'known' => self::all(),
            ]),
        };
    }
}
