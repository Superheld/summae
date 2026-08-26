<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Policies\Constraint\DimensionRegistry;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;

/**
 * What a tenant is configured as (F-CORE-035) — the read side of the four things
 * `summae_tenants.config` holds.
 *
 * Since SPEC-015 the library stores a tenant's configuration: the tax profile, the dimension
 * master data, the allocation scheme and the imported mappings. Exactly one of the four was
 * reported back — the tax profile, through `systemDescription` — and the other three could be
 * written and never read.
 *
 * The reason that is worse than an ordinary gap is the seed rule that came with the same release.
 * Before it, an embedding passed its cost centres in on every open, so *its* copy was the truth by
 * construction. Now the stored record wins and what the caller passes is ignored from the second
 * open on: summae's copy is the truth, the embedding's is a guess, and nothing let it check. A
 * screen offering a cost-centre field had no way to ask which values the engine would accept
 * except to post and read `E_DIMENSION_INVALID`.
 *
 * **It reports what is in force, not what is stored**, and where the two differ the difference is
 * the point:
 * - `dimensionRules` are the *pack's* — which accounts may not be posted without which dimension.
 *   They are never stored (they come back from the pack on every open) and an embedding cannot
 *   derive them, so a form cannot know which field it must not leave empty.
 * - `mappings` lists the pack's mappings *and* the imported ones. The record holds only the
 *   imports, so mirroring it would answer "none" for a `de` tenant whose `balanceSheet`,
 *   `incomeStatement` and `cashBasisReport` all work — the opposite of useful.
 *
 * Identity — id, name, base currency, pack — is deliberately not repeated here: that is
 * `systemDescription`'s block and it already reports all four. This projection answers the other
 * question, what the tenant was *set up* as.
 *
 * Deterministic: every list comes back sorted from the registry that owns it.
 *
 * The SAME shape lives in the Node tenant-configuration.ts.
 */
final readonly class TenantConfigurationProjection
{
    /**
     * @param array<string, mixed> $taxProfile
     * @param array<string, mixed>|null $allocationScheme raw as `setAllocationScheme` accepts it
     */
    public function __construct(
        private array $taxProfile,
        private DimensionRegistry $dimensions,
        private ?array $allocationScheme,
        private MappingRegistry $mappings,
        /**
         * Which appropriation targets the pack offers. Same reason as `dimensionRules`: it is the
         * *pack's* answer, an embedding cannot derive it, and without it a screen offering "carry
         * forward / distribute" would have to find out by provoking E_APPROPRIATION_UNSUPPORTED.
         * Empty means the pack supports no appropriation at all.
         *
         * @var list<string>
         */
        private array $appropriationTargets,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        unset($params);

        $masterData = $this->dimensions->toData();

        return [
            'taxProfile' => $this->taxProfile,
            'dimensionTypes' => $masterData['types'],
            'dimensionValues' => $masterData['values'],
            'dimensionRules' => $this->dimensions->rulesData(),
            'allocationScheme' => $this->allocationScheme,
            'mappings' => $this->mappings->summaries(),
            'appropriationTargets' => $this->appropriationTargets,
        ];
    }
}
