// Shared Kernel
export { Currency } from './substrate/currency.js';
export { Money } from './substrate/money.js';
export { canonicalJson } from './substrate/canonical-json.js';
export { type Clock, SystemClock, FixedClock } from './substrate/clock.js';
export { Uuid } from './substrate/uuid.js';
export {
  type IdGenerator,
  UuidV7IdGenerator,
  DeterministicIdGenerator,
} from './substrate/id-generator.js';
export { InvalidValue, CurrencyMismatch } from './substrate/errors.js';
export { CalendarDate } from './substrate/calendar-date.js';
export { AccountNumber } from './substrate/account-number.js';
export { PeriodRef } from './substrate/period-ref.js';
export { DimensionValue } from './substrate/dimension-value.js';

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
  AssetRepository,
  AuditTrail,
} from './port.js';
export {
  InMemoryAccountRepository,
  InMemoryFiscalYearRepository,
  InMemoryJournalRepository,
  InMemoryVoucherRepository,
  InMemoryOpenItemRepository,
  InMemoryAssetRepository,
  InMemoryAuditTrail,
} from './in-memory.js';

// Assets
export { Asset } from './policies/expansion/assets/asset.js';
export { type AssetRoute, parseAssetRoute } from './policies/expansion/assets/asset-route.js';
export { AssetService } from './policies/expansion/assets/asset-service.js';

// Costing
export { CostingRun } from './policies/expansion/costing/costing-run.js';
export { CostingService } from './policies/expansion/costing/costing-service.js';

// Partner
export { Partner } from './partner/partner.js';
export { PartnerService } from './partner/partner-service.js';
export type { PartnerRepository } from './port.js';
export { InMemoryPartnerRepository } from './in-memory.js';
export { EcSalesListProjection } from './projection/ec-sales-list.js';

// Projektionen
export { TrialBalanceProjection } from './projection/trial-balance.js';
export { OpenItemsProjection } from './projection/open-items.js';
export { AccountSheetProjection } from './projection/account-sheet.js';
export { AuditLogProjection } from './projection/audit-log.js';
export { VatReturnProjection } from './projection/vat-return.js';
export { IncomeStatementProjection } from './projection/income-statement.js';
export { BalanceSheetProjection } from './projection/balance-sheet.js';
export { CashBasisProjection } from './projection/cash-basis.js';
export { AssetRegisterProjection } from './projection/asset-register.js';
export { JournalExportProjection } from './projection/journal-export.js';
export { DatevExportProjection } from './projection/datev-export.js';

// Mappings
export { Mapping, type MappingLeaf, leafMatches } from './mapping/mapping.js';
export { MappingRegistry } from './mapping/mapping-registry.js';
export { MappingImporter } from './mapping/mapping-importer.js';

// Tax
export { TaxCode } from './policies/expansion/tax/tax-code.js';
export { TaxCodeVersion } from './policies/expansion/tax/tax-code-version.js';
export { TaxCodeRegistry } from './policies/expansion/tax/tax-code-registry.js';
export { TaxProfile } from './policies/expansion/tax/tax-profile.js';
export { TaxService } from './policies/expansion/tax/tax-service.js';

// Komposition
export { Tenant } from './composition/tenant.js';
export { TenantOperations } from './composition/tenant-operations.js';
export { PostVoucherService } from './composition/post-voucher-service.js';
export { TenantFactory } from './composition/tenant-factory.js';
export {
  resolvePack,
  ruleModulesFromResolved,
  type ResolvedPack,
  type PackModule,
  type PackManifest,
  type ModuleRef,
} from './composition/pack-resolver.js';
