import {
  type Clock,
  Currency,
  DimensionRegistry,
  emptyTenantConfig,
  type IdGenerator,
  Mapping,
  MappingRegistry,
  openTenantConfiguration,
  TaxCodeRegistry,
  TaxProfile,
  Tenant,
  type TenantRecordData,
  Uuid,
} from '@superheld/summae-core';
import {
  DatabaseAccountRepository,
  DatabaseAssetRepository,
  DatabaseAuditTrail,
  DatabaseFiscalYearRepository,
  DatabaseJournalRepository,
  DatabaseOpenItemRepository,
  DatabaseCostingRunRepository,
  DatabasePartnerRepository,
  DatabaseTenantRecordRepository,
  DatabaseVoucherRepository,
} from './repositories.js';
import type { SyncDb } from './sync-db.js';

export interface DatabaseTenantOptions {
  /** The tenant to open. Omitted means "a new one", and the IdGenerator names it. */
  tenantId?: Uuid;

  /**
   * Seed values — written on the **first** open of a tenant and ignored on every one after it,
   * because from then on the stored record is the truth (SPEC-015). Passing them again is harmless
   * and pointless; leaving them out once the tenant exists is the intended shape.
   */
  name?: string;
  baseCurrency?: Currency;
  taxProfile?: TaxProfile;
  dimensions?: DimensionRegistry;
  mappings?: MappingRegistry;

  /**
   * Pack data — supplied on **every** open, never stored. A pack is versioned product data the
   * embedding pins and ships; storing a copy of it beside the books would make two answers to
   * "which rules is this tenant on" out of one. `dimensions` is in both lists on purpose: its
   * *rules* (which accounts require a dimension) are the pack's and come from here, its types and
   * values are the tenant's and come from the record.
   */
  taxCodes?: TaxCodeRegistry;
  taxRoundingGranularity?: string;
  packIdentity?: { id: string; version: string } | null;
}

/**
 * Builds a `Tenant` with DB-backed ports — counterpart to PHP's `DatabaseTenantFactory::build`.
 * The schema must be installed beforehand (`installSchema`).
 *
 * Name and currency used to be required arguments, because nothing stored them and every open had
 * to be told again who this was. They are seed options now: `build(db, clock, ids, { tenantId })`
 * is the whole call for a tenant that exists.
 */
export class DatabaseTenantFactory {
  static build(db: SyncDb, clock: Clock, ids: IdGenerator, options: DatabaseTenantOptions = {}): Tenant {
    const tenantId = options.tenantId ?? ids.next();
    const records = new DatabaseTenantRecordRepository(db, tenantId);

    const seed: TenantRecordData = {
      id: tenantId.value,
      name: options.name ?? 'Tenant',
      baseCurrency: (options.baseCurrency ?? Currency.of('EUR')).code,
      packIdentity: options.packIdentity ?? null,
      config: {
        ...emptyTenantConfig(),
        taxProfile: (options.taxProfile ?? TaxProfile.default()).toJSON(),
        ...dimensionSeed(options.dimensions),
      },
    };

    const { record, store } = openTenantConfiguration(records, seed);
    const config = record.config;

    // The pack's rules, the tenant's master data — see `withMasterData`.
    const dimensions = (options.dimensions ?? DimensionRegistry.empty()).withMasterData(
      config.dimensionTypes,
      config.dimensionValues,
    );

    // Pack mappings first, then the imported ones on top: an import that replaced a pack mapping
    // has to keep winning after a restart, or the report changes shape when the process does.
    const mappings = options.mappings ?? MappingRegistry.empty();
    for (const mapping of config.mappings) mappings.add(Mapping.fromData(mapping));

    const tenant = Tenant.fromPorts(
      tenantId,
      record.name,
      Currency.of(record.baseCurrency, (options.baseCurrency ?? Currency.of('EUR')).scale),
      {
        accounts: new DatabaseAccountRepository(db, tenantId),
        fiscalYears: new DatabaseFiscalYearRepository(db, tenantId),
        vouchers: new DatabaseVoucherRepository(db, tenantId),
        journal: new DatabaseJournalRepository(db, tenantId),
        openItems: new DatabaseOpenItemRepository(db, tenantId),
        assets: new DatabaseAssetRepository(db, tenantId),
        partners: new DatabasePartnerRepository(db, tenantId),
        costingRuns: new DatabaseCostingRunRepository(db, tenantId),
        audit: new DatabaseAuditTrail(db, tenantId),
      },
      clock,
      ids,
      dimensions,
      options.taxCodes,
      config.taxProfile === null ? TaxProfile.default() : TaxProfile.fromData(config.taxProfile),
      mappings,
      options.taxRoundingGranularity,
      record.packIdentity,
      store,
    );

    // Replayed, not re-set: `restore…` runs the same validation without auditing a change nobody
    // made and without writing back what it just read.
    if (config.allocationScheme !== null) {
      tenant.costing.restoreAllocationScheme(config.allocationScheme);
    }

    return tenant;
  }
}

function dimensionSeed(
  registry: DimensionRegistry | undefined,
): Pick<TenantRecordData['config'], 'dimensionTypes' | 'dimensionValues'> {
  if (registry === undefined) return { dimensionTypes: [], dimensionValues: [] };
  const data = registry.toData();
  return { dimensionTypes: data.types, dimensionValues: data.values };
}
