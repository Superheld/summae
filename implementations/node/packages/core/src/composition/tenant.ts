import {
  InMemoryAccountRepository,
  InMemoryAuditTrail,
  InMemoryFiscalYearRepository,
  InMemoryJournalRepository,
  InMemoryOpenItemRepository,
  InMemoryVoucherRepository,
} from '../in-memory.js';
import { DimensionRegistry } from '../ledger/dimension-registry.js';
import { Ledger } from '../ledger/ledger.js';
import { MappingRegistry } from '../mapping/mapping-registry.js';
import { TaxCodeRegistry } from '../tax/tax-code-registry.js';
import { TaxProfile } from '../tax/tax-profile.js';
import { TaxService } from '../tax/tax-service.js';
import type {
  AccountRepository,
  AuditTrail,
  FiscalYearRepository,
  JournalRepository,
  OpenItemRepository,
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
    readonly audit: AuditTrail,
    readonly ledger: Ledger,
    readonly tax: TaxService,
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

    const accounts = new InMemoryAccountRepository();
    const fiscalYears = new InMemoryFiscalYearRepository();
    const vouchers = new InMemoryVoucherRepository();
    const journal = new InMemoryJournalRepository();
    const openItems = new InMemoryOpenItemRepository();
    const audit = new InMemoryAuditTrail();

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
      idGen,
    );
    const tax = new TaxService(baseCurrency, taxCodes, taxProfile, journal);

    return new Tenant(
      idGen.next(),
      name,
      baseCurrency,
      accounts,
      fiscalYears,
      vouchers,
      journal,
      openItems,
      audit,
      ledger,
      tax,
      mappings,
      clock,
      idGen,
    );
  }
}
