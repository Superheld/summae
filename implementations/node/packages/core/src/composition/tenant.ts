import {
  InMemoryAccountRepository,
  InMemoryAuditTrail,
  InMemoryFiscalYearRepository,
  InMemoryJournalRepository,
  InMemoryVoucherRepository,
} from '../in-memory.js';
import { DimensionRegistry } from '../ledger/dimension-registry.js';
import { Ledger } from '../ledger/ledger.js';
import type {
  AccountRepository,
  AuditTrail,
  FiscalYearRepository,
  JournalRepository,
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
    readonly audit: AuditTrail,
    readonly ledger: Ledger,
    readonly clock: Clock,
    readonly ids: IdGenerator,
  ) {}

  static inMemory(
    name: string,
    baseCurrency: Currency,
    clock: Clock = new SystemClock(),
    ids?: IdGenerator,
    dimensions: DimensionRegistry = DimensionRegistry.empty(),
  ): Tenant {
    const idGen = ids ?? new UuidV7IdGenerator(clock);

    const accounts = new InMemoryAccountRepository();
    const fiscalYears = new InMemoryFiscalYearRepository();
    const vouchers = new InMemoryVoucherRepository();
    const journal = new InMemoryJournalRepository();
    const audit = new InMemoryAuditTrail();

    const ledger = new Ledger(
      baseCurrency,
      accounts,
      fiscalYears,
      vouchers,
      journal,
      audit,
      dimensions,
      clock,
      idGen,
    );

    return new Tenant(
      idGen.next(),
      name,
      baseCurrency,
      accounts,
      fiscalYears,
      vouchers,
      journal,
      audit,
      ledger,
      clock,
      idGen,
    );
  }
}
