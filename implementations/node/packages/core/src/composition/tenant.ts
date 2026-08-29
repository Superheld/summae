import { AssetService } from '../policies/expansion/assets/asset-service.js';
import { ResultAppropriationService } from '../policies/expansion/result-appropriation-service.js';
import { EntityProfileService, LegalFormRegistry } from '../policies/projection/legal-forms.js';
import { CostingService } from '../policies/expansion/costing/costing-service.js';
import { InventoryService } from '../policies/expansion/inventory/inventory-service.js';
import { ProvisionService } from '../policies/expansion/provisions/provision-service.js';
import { DeferralService } from '../policies/expansion/deferrals/deferral-service.js';
import {
  InMemoryAccountRepository,
  InMemoryAssetRepository,
  InMemoryAuditTrail,
  InMemoryFiscalYearRepository,
  InMemoryJournalRepository,
  InMemoryOpenItemRepository,
  InMemoryCostingRunRepository,
  InMemoryInventoryValuationRepository,
  InMemoryProvisionRepository,
  InMemoryDeferralRepository,
  InMemoryPartnerRepository,
  InMemoryTenantRecordRepository,
  InMemoryVoucherRepository,
} from '../in-memory.js';
import { PartnerService } from '../partner/partner-service.js';
import { AccountCombinationRegistry } from '../policies/constraint/account-combination-registry.js';
import { DimensionRegistry } from '../policies/constraint/dimension-registry.js';
import { AuditWriter } from '../ledger/audit-writer.js';
import {
  emptyTenantConfig,
  openTenantConfiguration,
  type TenantConfigStore,
} from './tenant-config-store.js';
import { Ledger } from '../ledger/ledger.js';
import { MappingRegistry } from '../policies/projection/mapping/mapping-registry.js';
import { TaxCodeRegistry } from '../policies/expansion/tax/tax-code-registry.js';
import { TaxProfile } from '../policies/expansion/tax/tax-profile.js';
import { TaxService } from '../policies/expansion/tax/tax-service.js';
import type {
  AccountRepository,
  AssetRepository,
  AuditTrail,
  CostingRunRepository,
  InventoryValuationRepository,
  DeferralRepository,
  ProvisionRepository,
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
    /** Reachable from the tenant like every other repository, so `costingRuns` can read it. */
    readonly costingRuns: CostingRunRepository,
    /** Same, for stock (F-CORE-050) — `inventoryValuation` reads it. */
    readonly inventoryValuations: InventoryValuationRepository,
    /** Same, for provisions (F-CORE-051) — `provisionRegister` reads it. */
    readonly provisions: ProvisionRepository,
    /** Same, for prepaid and deferred items (F-CORE-053) — `deferralRegister` reads it. */
    readonly deferrals: DeferralRepository,
    readonly audit: AuditTrail,
    readonly ledger: Ledger,
    readonly tax: TaxService,
    readonly assetService: AssetService,
    readonly resultAppropriation: ResultAppropriationService,
    readonly costing: CostingService,
    readonly inventory: InventoryService,
    readonly provisionService: ProvisionService,
    readonly deferralService: DeferralService,
    readonly partnerService: PartnerService,
    readonly mappings: MappingRegistry,
    readonly clock: Clock,
    readonly ids: IdGenerator,
    /**
     * Which pack this tenant was composed from — null for an inline rule bundle, where there
     * is no manifest to name. Provenance, not rules: it exists so the system description can
     * say what the books were kept under (F-IO-007).
     */
    readonly packIdentity: { id: string; version: string } | null = null,
    /** Where configuration changes are kept; null when this tenant has no record (SPEC-015). */
    readonly configStore: TenantConfigStore | null = null,
    /**
     * What the embedding declares about the identity behind `actor` (SPEC-020). Null = it has not
     * said, which `systemDescription` reports as null rather than as "no". Not stored: it describes
     * the running installation, not the books.
     */
    readonly actorAuthentication: { declared: boolean; method: string | null } | null = null,
    /**
     * Which legal forms the pack knows and which one this tenant is (F-CORE-039). Unlike
     * `actorAuthentication` this one IS stored — it describes the entity whose books these are, not
     * the installation running them, and changing it is an audited event with a date.
     */
    readonly legalForms: LegalFormRegistry = LegalFormRegistry.empty(),
    readonly entityProfile: EntityProfileService | null = null,
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
    packIdentity: { id: string; version: string } | null = null,
    actorAuthentication: { declared: boolean; method: string | null } | null = null,
    /**
     * The constraint socket's second plug (F-CORE-042). Appended rather than slotted next to
     * `dimensions`, where it belongs by subject: thirteen call sites take the defaults, and moving
     * a positional parameter to keep two related arguments adjacent would have edited all of them
     * to say the same thing they already say.
     */
    combinations: AccountCombinationRegistry = AccountCombinationRegistry.empty(),
  ): Tenant {
    const idGen = ids ?? new UuidV7IdGenerator(clock);
    const tenantId = idGen.next(); // tenant ID = first generated ID (determinism)
    // An in-memory tenant gets a record too, so the four configuration operations behave the same
    // way here as they do behind a database. It buys nothing within one process — which is exactly
    // why the defect could hide from every fixture — but one code path is worth more than the
    // saving, and a core test can now prove the round trip without an adapter.
    const { store } = openTenantConfiguration(new InMemoryTenantRecordRepository(), {
      id: tenantId.value,
      name,
      baseCurrency: baseCurrency.code,
      packIdentity,
      config: emptyTenantConfig(),
    });
    return Tenant.fromPorts(
      tenantId,
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
        costingRuns: new InMemoryCostingRunRepository(),
        inventoryValuations: new InMemoryInventoryValuationRepository(),
        provisions: new InMemoryProvisionRepository(),
        deferrals: new InMemoryDeferralRepository(),
        audit: new InMemoryAuditTrail(),
      },
      clock,
      idGen,
      dimensions,
      taxCodes,
      taxProfile,
      mappings,
      taxRoundingGranularity,
      packIdentity,
      store,
      actorAuthentication,
      combinations,
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
      costingRuns: CostingRunRepository;
      inventoryValuations: InventoryValuationRepository;
      provisions: ProvisionRepository;
      deferrals: DeferralRepository;
      audit: AuditTrail;
    },
    clock: Clock,
    ids: IdGenerator,
    dimensions: DimensionRegistry = DimensionRegistry.empty(),
    taxCodes: TaxCodeRegistry = TaxCodeRegistry.empty(),
    taxProfile: TaxProfile = TaxProfile.default(),
    mappings: MappingRegistry = MappingRegistry.empty(),
    taxRoundingGranularity = 'perVoucher',
    packIdentity: { id: string; version: string } | null = null,
    /**
     * Where configuration changes are kept (SPEC-015). Null leaves the four configuration
     * operations effective for this object only — which is what every tenant did before the
     * record existed, and what an adapter without the table still does.
     */
    configStore: TenantConfigStore | null = null,
    /** See the constructor: the embedding's declaration about `actor` (SPEC-020). */
    actorAuthentication: { declared: boolean; method: string | null } | null = null,
    /** The constraint socket's second plug (F-CORE-042) — see `inMemory` for why it is last. */
    combinations: AccountCombinationRegistry = AccountCombinationRegistry.empty(),
  ): Tenant {
    const {
      accounts,
      fiscalYears,
      vouchers,
      journal,
      openItems,
      assets,
      partners,
      costingRuns,
      inventoryValuations,
      provisions,
      deferrals,
      audit,
    } = ports;
    // Built before the ledger, not with the other services below: the ledger reads the declared
    // legal form on every posting to evaluate conditional constraint rules (F-CORE-047), and it
    // must hold the same object `setEntityProfile` later writes to.
    const legalForms = new LegalFormRegistry();
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
      taxCodes,
      tenantId,
      configStore,
      combinations,
      legalForms,
      taxProfile,
    );
    const auditWriter = new AuditWriter(audit, clock, ids);
    const tax = new TaxService(
      baseCurrency,
      taxCodes,
      taxProfile,
      journal,
      taxRoundingGranularity,
      tenantId,
      auditWriter,
      configStore,
    );
    const assetService = new AssetService(baseCurrency, assets, fiscalYears, vouchers, ledger, ids, tenantId, auditWriter);
    const resultAppropriation = new ResultAppropriationService(baseCurrency, accounts, journal, ledger, auditWriter);
    const costing = new CostingService(
      baseCurrency,
      accounts,
      journal,
      costingRuns,
      ids,
      tenantId,
      auditWriter,
      configStore,
    );
    const inventory = new InventoryService(
      baseCurrency,
      accounts,
      journal,
      vouchers,
      costingRuns,
      inventoryValuations,
      ledger,
      ids,
      {},
      tenantId,
      auditWriter,
    );
    const provisionService = new ProvisionService(
      baseCurrency,
      accounts,
      vouchers,
      provisions,
      ledger,
      ids,
      {},
      auditWriter,
    );
    const deferralService = new DeferralService(
      baseCurrency,
      accounts,
      fiscalYears,
      vouchers,
      deferrals,
      ledger,
      ids,
      {},
      tenantId,
      auditWriter,
    );
    const partnerService = new PartnerService(partners, audit, clock, ids, accounts, vouchers, openItems);
    const entityProfile = new EntityProfileService(legalForms, auditWriter, tenantId, configStore);

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
      costingRuns,
      inventoryValuations,
      provisions,
      deferrals,
      audit,
      ledger,
      tax,
      assetService,
      resultAppropriation,
      costing,
      inventory,
      provisionService,
      deferralService,
      partnerService,
      mappings,
      clock,
      ids,
      packIdentity,
      configStore,
      actorAuthentication,
      legalForms,
      entityProfile,
    );
  }
}
