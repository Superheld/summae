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
  DatabaseInventoryValuationRepository,
  DatabaseProvisionRepository,
  DatabaseDeferralRepository,
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
  /** The embedding's declaration about `actor` (SPEC-020) — passed on every open, never stored. */
  actorAuthentication?: { declared: boolean; method: string | null } | null;
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

    // The scale is a PACK parameter (`packPolicy.currencyScale`), so it comes from the caller on
    // every open — not from the record, which stores the code alone. Absent means "whatever this
    // currency's own scale is", which is not the same as EUR's. Hoisted out of the call because the
    // repositories that hydrate money need it too: reading an amount on the ISO default when the
    // tenant runs on another scale is IMPL-040.
    const baseCurrency = Currency.of(record.baseCurrency, options.baseCurrency?.scale);

    const tenant = Tenant.fromPorts(
      tenantId,
      record.name,
      baseCurrency,
      {
        accounts: new DatabaseAccountRepository(db, tenantId),
        fiscalYears: new DatabaseFiscalYearRepository(db, tenantId),
        vouchers: new DatabaseVoucherRepository(db, tenantId),
        journal: new DatabaseJournalRepository(db, tenantId, baseCurrency),
        openItems: new DatabaseOpenItemRepository(db, tenantId, baseCurrency),
        assets: new DatabaseAssetRepository(db, tenantId, baseCurrency),
        partners: new DatabasePartnerRepository(db, tenantId),
        costingRuns: new DatabaseCostingRunRepository(db, tenantId, baseCurrency),
        inventoryValuations: new DatabaseInventoryValuationRepository(db, tenantId, baseCurrency),
        provisions: new DatabaseProvisionRepository(db, tenantId, baseCurrency),
        deferrals: new DatabaseDeferralRepository(db, tenantId, baseCurrency),
        audit: new DatabaseAuditTrail(db, tenantId),
      },
      clock,
      ids,
      dimensions,
      options.taxCodes,
      // `restore`, not `fromData`: these values were validated when they arrived, and re-checking
      // them on the way out of our own store would stop a tenant opening after its pack drops a
      // filing window — a rule change reaching backwards into books kept correctly (SPEC-016).
      config.taxProfile === null ? TaxProfile.default() : TaxProfile.restore(config.taxProfile),
      mappings,
      options.taxRoundingGranularity,
      record.packIdentity,
      store,
      options.actorAuthentication ?? null,
    );

    // Replayed, not re-set: `restore…` runs the same validation without auditing a change nobody
    // made and without writing back what it just read.
    if (config.allocationScheme !== null) {
      tenant.costing.restoreAllocationScheme(config.allocationScheme);
    }
    // Same idea, and lenient on purpose (F-CORE-039): the catalogue itself arrives with the pack on
    // every open, so what the record holds is only WHICH form was declared. A pack that has since
    // dropped that form makes the rule stop applying, never the tenant stop opening.
    tenant.legalForms.restore(config.entityProfile);

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
