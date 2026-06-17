// Shared Kernel
export { Currency } from './shared/currency.js';
export { Money } from './shared/money.js';
export { canonicalJson } from './shared/canonical-json.js';
export { type Clock, SystemClock, FixedClock } from './shared/clock.js';
export { Uuid } from './shared/uuid.js';
export {
  type IdGenerator,
  UuidV7IdGenerator,
  DeterministicIdGenerator,
} from './shared/id-generator.js';
export { InvalidValue, CurrencyMismatch } from './shared/errors.js';
export { CalendarDate } from './shared/calendar-date.js';
export { AccountNumber } from './shared/account-number.js';
export { PeriodRef } from './shared/period-ref.js';
export { DimensionValue } from './shared/dimension-value.js';

// Fehler
export { DomainError } from './domain-error.js';

// Ledger
export {
  type Side,
  type AccountType,
  type AccountStatus,
  type EntryStatus,
  type PeriodStatus,
  type FiscalYearStatus,
  type OpenItemKind,
  type OpenItemStatus,
  type SettlementDifferenceKind,
  isAccountType,
  isBalanceCarrying,
  parseOpenItemKind,
  parseSettlementDifferenceKind,
} from './ledger/types.js';
export { Account } from './ledger/account.js';
export { EntryLine } from './ledger/entry-line.js';
export { JournalEntry } from './ledger/journal-entry.js';
export { Voucher, type VoucherProps } from './ledger/voucher.js';
export { Period } from './ledger/period.js';
export { FiscalYear, type PeriodDefinition } from './ledger/fiscal-year.js';
export { AuditRecord, type AuditChanges } from './ledger/audit-record.js';
export { OpenItem } from './ledger/open-item.js';
export { Settlement } from './ledger/settlement.js';
export { PostResult } from './ledger/post-result.js';
export {
  DimensionRegistry,
  type DimensionTypeData,
  type DimensionValueData,
  type DimensionRuleData,
} from './ledger/dimension-registry.js';
export { Ledger } from './ledger/ledger.js';

// Ports & Adapter
export type {
  AccountRepository,
  FiscalYearRepository,
  JournalRepository,
  VoucherRepository,
  OpenItemRepository,
  AuditTrail,
} from './port.js';
export {
  InMemoryAccountRepository,
  InMemoryFiscalYearRepository,
  InMemoryJournalRepository,
  InMemoryVoucherRepository,
  InMemoryOpenItemRepository,
  InMemoryAuditTrail,
} from './in-memory.js';

// Projektionen
export { TrialBalanceProjection } from './projection/trial-balance.js';
export { OpenItemsProjection } from './projection/open-items.js';

// Komposition
export { Tenant } from './composition/tenant.js';
export { TenantOperations } from './composition/tenant-operations.js';
