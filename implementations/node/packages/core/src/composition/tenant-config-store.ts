import type { TenantRecordData, TenantRecordRepository } from '../port.js';

/**
 * Keeps a tenant's configuration where the books already live (SPEC-015).
 *
 * Six operations change configuration — `setTaxProfile`, `defineDimensionType`,
 * `defineDimensionValue`, `setAllocationScheme`, `importMapping` and `setEntityProfile` — and until
 * this existed each of them changed a live object and nothing else. They audited the change durably all the same, which
 * is the part that made it a defect rather than a limitation: the trail stated something the books
 * stopped carrying at the next restart.
 *
 * The store is the one place that writes. Each `remember…` is called by the service that just
 * succeeded, never before, so a rejected operation stores nothing — the same discipline the CLI's
 * `rememberMapping` had to invent for one of the five.
 */
export class TenantConfigStore {
  constructor(
    private readonly repository: TenantRecordRepository,
    private readonly record: TenantRecordData,
  ) {}

  rememberTaxProfile(profile: Record<string, unknown>): void {
    this.record.config.taxProfile = profile;
    this.flush();
  }

  rememberDimensions(
    types: Array<{ code: string }>,
    values: Array<{ typeCode: string; code: string }>,
  ): void {
    this.record.config.dimensionTypes = types;
    this.record.config.dimensionValues = values;
    this.flush();
  }

  rememberEntityProfile(profile: { legalForm: string; sizeClass: string | null }): void {
    this.record.config.entityProfile = profile;
    this.flush();
  }

  rememberAllocationScheme(scheme: Record<string, unknown>): void {
    this.record.config.allocationScheme = scheme;
    this.flush();
  }

  /**
   * Replace by id rather than append: importing the same id twice must update it, not leave two
   * mappings behind that the next load would read as overlapping. (The rule comes from the CLI,
   * which learned it the hard way; it belongs here now that the library does the storing.)
   */
  rememberMapping(mapping: Record<string, unknown>): void {
    const id = typeof mapping.id === 'string' ? mapping.id : null;
    const mappings = [...this.record.config.mappings];
    const index = mappings.findIndex((m) => typeof m.id === 'string' && m.id === id);
    if (index === -1) mappings.push(mapping);
    else mappings[index] = mapping;
    this.record.config.mappings = mappings;
    this.flush();
  }

  /** What is stored right now — the seed written at first open, or what the operations made of it. */
  snapshot(): TenantRecordData {
    return JSON.parse(JSON.stringify(this.record)) as TenantRecordData;
  }

  private flush(): void {
    this.repository.save(this.record);
  }
}

/**
 * Opens a tenant's stored configuration, seeding it on first open.
 *
 * **The stored record wins.** What the caller passes at construction is a *seed*: it is written on
 * the first open of a tenant that has no record yet, and ignored on every open after that. The
 * alternative — the arguments win when given — sounds harmless and is the state this whole finding
 * came out of: an embedding's configuration file and the library's tables would both claim to hold
 * the truth, and the two would drift the first time an operation changed one of them.
 *
 * Living with the rule is what makes `defineDimensionType` work: an embedding that used to pass its
 * cost centres in AND declare them (and got `E_DIMENSION_INVALID` for its trouble) can now do
 * either one.
 *
 * The rule lives in the core, not in the adapters, so both languages cannot answer it differently.
 */
export function openTenantConfiguration(
  repository: TenantRecordRepository,
  seed: TenantRecordData,
): { record: TenantRecordData; store: TenantConfigStore; seeded: boolean } {
  const stored = repository.load();
  if (stored !== null) {
    return { record: stored, store: new TenantConfigStore(repository, stored), seeded: false };
  }
  repository.save(seed);
  return { record: seed, store: new TenantConfigStore(repository, seed), seeded: true };
}

/** An empty configuration block — the shape every record carries, with nothing configured yet. */
export function emptyTenantConfig(): TenantRecordData['config'] {
  return {
    taxProfile: null,
    dimensionTypes: [],
    dimensionValues: [],
    allocationScheme: null,
    mappings: [],
    entityProfile: null,
  };
}
