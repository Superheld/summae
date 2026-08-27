import type { DimensionRegistry } from '../constraint/dimension-registry.js';
import type { MappingRegistry } from './mapping/mapping-registry.js';

/**
 * What a tenant is configured as (F-CORE-035) — the read side of the four things
 * `summae_tenants.config` holds.
 *
 * Since SPEC-015 the library stores a tenant's configuration: the tax profile, the dimension master
 * data, the allocation scheme and the imported mappings. Exactly one of the four was reported back
 * — the tax profile, through `systemDescription` — and the other three could be written and never
 * read.
 *
 * The reason that is worse than an ordinary gap is the seed rule that came with the same release.
 * Before it, an embedding passed its cost centres in on every open, so *its* copy was the truth by
 * construction. Now the stored record wins and what the caller passes is ignored from the second
 * open on: summae's copy is the truth, the embedding's is a guess, and nothing let it check. A
 * screen offering a cost-centre field had no way to ask which values the engine would accept except
 * to post and read `E_DIMENSION_INVALID`.
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
 * The SAME shape lives in the PHP TenantConfigurationProjection.
 */
export class TenantConfigurationProjection {
  constructor(
    private readonly taxProfile: Record<string, unknown>,
    private readonly dimensions: DimensionRegistry,
    /** Raw, as `setAllocationScheme` accepts it. */
    private readonly allocationScheme: Record<string, unknown> | null,
    private readonly mappings: MappingRegistry,
    /**
     * Which appropriation targets the pack offers. Same reason as `dimensionRules`: it is the
     * *pack's* answer, an embedding cannot derive it, and without it a screen offering "carry
     * forward / distribute" would have to find out by provoking E_APPROPRIATION_UNSUPPORTED.
     * Empty means the pack supports no appropriation at all.
     */
    private readonly appropriationTargets: string[],
    /**
     * What the entity IS, and what it could be. Same reason again: which legal forms exist is the
     * *pack's* answer, and the tenant's own form is stored library state an embedding cannot derive
     * — a screen offering "Rechtsform" would otherwise have to carry its own list and hope the two
     * agree. `entityProfile` is null until `setEntityProfile` has been called; `legalForms` is empty
     * for a pack that ships no catalogue.
     */
    private readonly entityProfile: { legalForm: string; sizeClass: string | null } | null,
    private readonly legalForms: string[],
    private readonly sizeClasses: string[],
  ) {}

  compute(_params: Record<string, unknown>): Record<string, unknown> {
    const masterData = this.dimensions.toData();

    return {
      taxProfile: this.taxProfile,
      dimensionTypes: masterData.types,
      dimensionValues: masterData.values,
      dimensionRules: this.dimensions.rulesData(),
      allocationScheme: this.allocationScheme,
      mappings: this.mappings.summaries(),
      appropriationTargets: [...this.appropriationTargets],
      entityProfile: this.entityProfile,
      legalForms: [...this.legalForms],
      sizeClasses: [...this.sizeClasses],
    };
  }
}
