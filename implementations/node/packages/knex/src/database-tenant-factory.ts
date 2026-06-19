import {
  type Clock,
  type Currency,
  type DimensionRegistry,
  type IdGenerator,
  type MappingRegistry,
  type TaxCodeRegistry,
  type TaxProfile,
  Tenant,
  type Uuid,
} from '@superheld/summae-core';
import {
  DatabaseAccountRepository,
  DatabaseAssetRepository,
  DatabaseAuditTrail,
  DatabaseFiscalYearRepository,
  DatabaseJournalRepository,
  DatabaseOpenItemRepository,
  DatabasePartnerRepository,
  DatabaseVoucherRepository,
} from './repositories.js';
import type { SyncDb } from './sync-db.js';

export interface DatabaseTenantOptions {
  /** Mandanten-ID vorgeben (sonst aus dem IdGenerator). */
  tenantId?: Uuid;
  dimensions?: DimensionRegistry;
  taxCodes?: TaxCodeRegistry;
  taxProfile?: TaxProfile;
  mappings?: MappingRegistry;
}

/**
 * Baut einen `Tenant` mit DB-gestützten Ports — Pendant zu PHPs
 * `DatabaseTenantFactory::build`. Gleiche Services wie `Tenant.inMemory`, nur
 * persistente Ports. Das Schema muss vorher installiert sein (`installSchema`).
 */
export class DatabaseTenantFactory {
  static build(
    db: SyncDb,
    name: string,
    baseCurrency: Currency,
    clock: Clock,
    ids: IdGenerator,
    options: DatabaseTenantOptions = {},
  ): Tenant {
    const tenantId = options.tenantId ?? ids.next();
    return Tenant.fromPorts(
      tenantId,
      name,
      baseCurrency,
      {
        accounts: new DatabaseAccountRepository(db, tenantId),
        fiscalYears: new DatabaseFiscalYearRepository(db, tenantId),
        vouchers: new DatabaseVoucherRepository(db, tenantId),
        journal: new DatabaseJournalRepository(db, tenantId),
        openItems: new DatabaseOpenItemRepository(db, tenantId),
        assets: new DatabaseAssetRepository(db, tenantId),
        partners: new DatabasePartnerRepository(db, tenantId),
        audit: new DatabaseAuditTrail(db, tenantId),
      },
      clock,
      ids,
      options.dimensions,
      options.taxCodes,
      options.taxProfile,
      options.mappings,
    );
  }
}
