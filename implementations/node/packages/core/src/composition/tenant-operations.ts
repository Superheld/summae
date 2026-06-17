import { DomainError } from '../domain-error.js';
import { MappingImporter } from '../mapping/mapping-importer.js';
import { AccountSheetProjection } from '../projection/account-sheet.js';
import { AuditLogProjection } from '../projection/audit-log.js';
import { BalanceSheetProjection } from '../projection/balance-sheet.js';
import { IncomeStatementProjection } from '../projection/income-statement.js';
import { OpenItemsProjection } from '../projection/open-items.js';
import { TrialBalanceProjection } from '../projection/trial-balance.js';
import { VatReturnProjection } from '../projection/vat-return.js';
import { PostVoucherService } from './post-voucher-service.js';
import type { Tenant } from './tenant.js';

/** Plain-JSON-Serialisierung über toJSON() (wie PHPs json_encode/decode). */
function serialize(value: unknown): Record<string, unknown> {
  return JSON.parse(JSON.stringify(value)) as Record<string, unknown>;
}

/**
 * Generischer Einstieg in alle Operationen und Projektionen eines Mandanten —
 * die Schnittstelle für CLI und Konformitäts-Runner. Namen exakt nach api.md.
 * Wächst mit den Slices; Unimplementiertes meldet E_NOT_IMPLEMENTED.
 */
export class TenantOperations {
  constructor(private readonly tenant: Tenant) {}

  execute(op: string, input: Record<string, unknown>): Record<string, unknown> {
    const ledger = this.tenant.ledger;

    switch (op) {
      case 'expandTax':
        return this.tenant.tax.expand(input);
      case 'setTaxProfile':
        return serialize(this.tenant.tax.setProfile(input));
      case 'postVoucher':
        return new PostVoucherService(this.tenant).post(input);
      case 'post': {
        const result = ledger.post(input);
        return {
          ...serialize(result.entry),
          openItemsCreated: result.openItemsCreated.map((item) => serialize(item)),
        };
      }
      case 'correct':
        return serialize(ledger.correct(input));
      case 'settle':
        return { openItems: ledger.settle(input).map((item) => serialize(item)) };
      case 'finalize':
        return { finalizedCount: ledger.finalize(input) };
      case 'reverse':
        return serialize(ledger.reverse(input));
      case 'closePeriod':
        return ledger.closePeriod(input);
      case 'reopenPeriod':
        return ledger.reopenPeriod(input);
      case 'closeFiscalYear':
        return { fiscalYear: ledger.closeFiscalYear(input).year, status: 'closed' };
      case 'createAccount':
        return serialize(ledger.createAccount(input));
      case 'createFiscalYear': {
        const fiscalYear = ledger.createFiscalYear(input);
        return { year: fiscalYear.year, periodCount: fiscalYear.periods().length };
      }
      case 'lockAccount':
        return serialize(ledger.lockAccount(input));
      case 'importChartOfAccounts':
        return { importedCount: ledger.importChartOfAccounts(input) };
      case 'importMapping':
        return new MappingImporter(this.tenant.accounts, this.tenant.mappings).import(input);
      default:
        throw new DomainError('E_NOT_IMPLEMENTED', `Operation "${op}" ist nicht definiert`);
    }
  }

  project(name: string, params: Record<string, unknown>): Record<string, unknown> {
    const tenant = this.tenant;

    switch (name) {
      case 'trialBalance':
        return new TrialBalanceProjection(tenant.baseCurrency, tenant.accounts, tenant.journal).compute(params);
      case 'openItems':
        return new OpenItemsProjection(tenant.openItems, tenant.vouchers, tenant.journal).compute(params);
      case 'accountSheet':
        return new AccountSheetProjection(tenant.baseCurrency, tenant.accounts, tenant.journal).compute(params);
      case 'auditLog':
        return new AuditLogProjection(tenant.audit).compute(params);
      case 'incomeStatement':
        return new IncomeStatementProjection(
          tenant.baseCurrency,
          tenant.accounts,
          tenant.journal,
          tenant.mappings,
        ).compute(params);
      case 'balanceSheet':
        return new BalanceSheetProjection(
          tenant.baseCurrency,
          tenant.accounts,
          tenant.journal,
          tenant.mappings,
        ).compute(params);
      case 'vatReturn':
        return new VatReturnProjection(
          tenant.baseCurrency,
          tenant.journal,
          tenant.openItems,
          tenant.vouchers,
          tenant.accounts,
          tenant.tax.registryHandle(),
          tenant.tax.profile(),
        ).compute(params);
      default:
        throw new DomainError('E_NOT_IMPLEMENTED', `Projektion "${name}" ist nicht definiert`);
    }
  }
}
