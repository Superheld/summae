import { AssetService } from '../policies/expansion/assets/asset-service.js';
import { CostingService } from '../policies/expansion/costing/costing-service.js';
import {
  InMemoryAccountRepository,
  InMemoryAssetRepository,
  InMemoryAuditTrail,
  InMemoryFiscalYearRepository,
  InMemoryJournalRepository,
  InMemoryOpenItemRepository,
  InMemoryPartnerRepository,
  InMemoryVoucherRepository,
} from '../in-memory.js';
import { PartnerService } from '../partner/partner-service.js';
import { DimensionRegistry } from '../policies/constraint/dimension-registry.js';
import { Ledger } from '../ledger/ledger.js';
import { MappingRegistry } from '../policies/projection/mapping/mapping-registry.js';
import { TaxCodeRegistry } from '../policies/expansion/tax/tax-code-registry.js';
import { TaxProfile } from '../policies/expansion/tax/tax-profile.js';
import { TaxService } from '../policies/expansion/tax/tax-service.js';
import type {
  AccountRepository,
  AssetRepository,
  AuditTrail,
  FiscalYearRepository,
  JournalRepository,
  OpenItemRepository,
  PartnerRepository,
  VoucherRepository,
} from '../port.js';
import { type Clock, SystemClock } from '../substrate/clock.js';
import type { Currency } from '../substrate/currency.js';
import { type IdGenerator, UuidV7IdGenerator } from '../substrate/id-generator.js';
import type { Uuid } from '../substrate/uuid.js';

/**
 * Tenant: bookkeeping unit, top-most data boundary (glossary `tenant`). Bundles
 * ports + services of one instance. Grows with the slices (Tax/Partner/Asset/
 * Costing follow); the adapter replaces only the ports.
 */
export class Tenant {
  constructor(
    readonly id: Uuid,
    readonly name: string,
    readonly baseCurrency: Currency,
    readonly accounts: AccountRepository,
    readonly fiscalYears: FiscalYearRepository,
    readonly vouchers: VoucherRepository,
    readonly journal: JournalRepository,
    readonly openItems: OpenItemRepository,
    readonly assets: AssetRepository,
    readonly partners: PartnerRepository,
    readonly audit: AuditTrail,
    readonly ledger: Ledger,
    readonly tax: TaxService,
    readonly assetService: AssetService,
    readonly costing: CostingService,
    readonly partnerService: PartnerService,
    readonly mappings: MappingRegistry,
    readonly clock: Clock,
    readonly ids: IdGenerator,
  ) {}

  static inMemory(
    name: string,
    baseCurrency: Currency,
    clock: Clock = new SystemClock(),
    ids?: IdGenerator,
    dimensions: DimensionRegistry = DimensionRegistry.empty(),
    taxCodes: TaxCodeRegistry = TaxCodeRegistry.empty(),
    taxProfile: TaxProfile = TaxProfile.default(),
    mappings: MappingRegistry = MappingRegistry.empty(),
    taxRoundingGranularity = 'perVoucher',
  ): Tenant {
    const idGen = ids ?? new UuidV7IdGenerator(clock);
    return Tenant.fromPorts(
      idGen.next(), // tenant ID = first generated ID (determinism)
      name,
      baseCurrency,
      {
        accounts: new InMemoryAccountRepository(),
        fiscalYears: new InMemoryFiscalYearRepository(),
        vouchers: new InMemoryVoucherRepository(),
        journal: new InMemoryJournalRepository(),
        openItems: new InMemoryOpenItemRepository(),
        assets: new InMemoryAssetRepository(),
        partners: new InMemoryPartnerRepository(),
        audit: new InMemoryAuditTrail(),
      },
      clock,
      idGen,
      dimensions,
      taxCodes,
      taxProfile,
      mappings,
      taxRoundingGranularity,
    );
  }

  /**
   * Build a tenant from arbitrary ports (service wiring stays here in the core).
   * `inMemory` uses this with in-memory ports; the persistence adapter
   * (`@superheld/summae-knex`) passes in DB-backed ports — the same
   * `Tenant`, only different ports.
   */
  static fromPorts(
    tenantId: Uuid,
    name: string,
    baseCurrency: Currency,
    ports: {
      accounts: AccountRepository;
      fiscalYears: FiscalYearRepository;
      vouchers: VoucherRepository;
      journal: JournalRepository;
      openItems: OpenItemRepository;
      assets: AssetRepository;
      partners: PartnerRepository;
      audit: AuditTrail;
    },
    clock: Clock,
    ids: IdGenerator,
    dimensions: DimensionRegistry = DimensionRegistry.empty(),
    taxCodes: TaxCodeRegistry = TaxCodeRegistry.empty(),
    taxProfile: TaxProfile = TaxProfile.default(),
    mappings: MappingRegistry = MappingRegistry.empty(),
    taxRoundingGranularity = 'perVoucher',
  ): Tenant {
    const { accounts, fiscalYears, vouchers, journal, openItems, assets, partners, audit } = ports;
    const ledger = new Ledger(
      baseCurrency,
      accounts,
      fiscalYears,
      vouchers,
      journal,
      openItems,
      audit,
      dimensions,
      clock,
      ids,
    );
    const tax = new TaxService(baseCurrency, taxCodes, taxProfile, journal, taxRoundingGranularity);
    const assetService = new AssetService(baseCurrency, assets, fiscalYears, vouchers, ledger, ids);
    const costing = new CostingService(baseCurrency, accounts, journal, ids);
    const partnerService = new PartnerService(partners, audit, clock, ids);

    return new Tenant(
      tenantId,
      name,
      baseCurrency,
      accounts,
      fiscalYears,
      vouchers,
      journal,
      openItems,
      assets,
      partners,
      audit,
      ledger,
      tax,
      assetService,
      costing,
      partnerService,
      mappings,
      clock,
      ids,
    );
  }
}
