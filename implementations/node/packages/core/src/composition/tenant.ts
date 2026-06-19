import { AssetService } from '../assets/asset-service.js';
import { CostingService } from '../costing/costing-service.js';
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
import { DimensionRegistry } from '../ledger/dimension-registry.js';
import { Ledger } from '../ledger/ledger.js';
import { MappingRegistry } from '../mapping/mapping-registry.js';
import { TaxCodeRegistry } from '../tax/tax-code-registry.js';
import { TaxProfile } from '../tax/tax-profile.js';
import { TaxService } from '../tax/tax-service.js';
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
import { type Clock, SystemClock } from '../shared/clock.js';
import type { Currency } from '../shared/currency.js';
import { type IdGenerator, UuidV7IdGenerator } from '../shared/id-generator.js';
import type { Uuid } from '../shared/uuid.js';

/**
 * Mandant: buchführende Einheit, oberste Datengrenze (Glossar `tenant`). Bündelt
 * Ports + Services einer Instanz. Wächst mit den Slices (Tax/Partner/Asset/
 * Costing folgen); der Adapter ersetzt nur die Ports.
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
  ): Tenant {
    const idGen = ids ?? new UuidV7IdGenerator(clock);
    return Tenant.fromPorts(
      idGen.next(), // Mandanten-ID = erste generierte ID (Determinismus)
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
    );
  }

  /**
   * Mandant aus beliebigen Ports bauen (Service-Verdrahtung bleibt hier im Kern).
   * `inMemory` nutzt das mit In-Memory-Ports; der Persistenz-Adapter
   * (`@superheld/summae-knex`) reicht DB-gestützte Ports herein — derselbe
   * `Tenant`, nur andere Ports.
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
    const tax = new TaxService(baseCurrency, taxCodes, taxProfile, journal);
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
