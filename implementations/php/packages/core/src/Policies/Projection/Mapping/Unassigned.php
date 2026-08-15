<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection\Mapping;

/**
 * The catch-all a mapping gap falls into.
 *
 * The error catalogue settles the treatment: a gap is not an error but `gapWarnings[]` plus this
 * position. Three places need to agree on the key — the importer, which reports gaps at import
 * time, and both statements, which have to keep the money visible at report time — so it lives
 * here rather than as three string literals that would eventually disagree.
 */
final class Unassigned
{
    public const string KEY = '_unassigned';

    /**
     * Label for the catch-all. Neutral and jurisdiction-free on purpose: there is no mapping entry
     * to take a label from, and the core carries no statute language (SubstrateBoundary guard).
     */
    public const string LABEL = 'Unassigned';
}
