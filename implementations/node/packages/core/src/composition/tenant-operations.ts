import { DomainError } from '../domain-error.js';
import { Money } from '../substrate/money.js';
import { AssetRegisterProjection } from '../policies/projection/asset-register.js';
import { AuditDataExportProjection } from '../policies/projection/audit-data-export.js';
import { MappingImporter } from '../policies/projection/mapping/mapping-importer.js';
import { AccountSheetProjection } from '../policies/projection/account-sheet.js';
import { AuditLogProjection } from '../policies/projection/audit-log.js';
import { BalanceSheetProjection } from '../policies/projection/balance-sheet.js';
import { CashBasisProjection } from '../policies/projection/cash-basis.js';
import { DatevExportProjection } from '../policies/projection/datev-export.js';
import { JournalExportProjection } from '../policies/projection/journal-export.js';
import { IncomeStatementProjection } from '../policies/projection/income-statement.js';
import { EcSalesListProjection } from '../policies/projection/ec-sales-list.js';
import { OpenItemsProjection } from '../policies/projection/open-items.js';
import { TrialBalanceProjection } from '../policies/projection/trial-balance.js';
import { VatReturnProjection } from '../policies/projection/vat-return.js';
import { PostVoucherService } from './post-voucher-service.js';
import type { Tenant } from './tenant.js';

/** Plain-JSON serialization via toJSON() (like PHP's json_encode/decode). */
function serialize(value: unknown): Record<string, unknown> {
  return JSON.parse(JSON.stringify(value)) as Record<string, unknown>;
}

/**
 * Generic entry into all operations and projections of a tenant —
 * the interface for CLI and conformance runner. Names exactly per api.md.
 * Grows with the slices; the unimplemented reports E_NOT_IMPLEMENTED.
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
      case 'createVoucher':
        return new PostVoucherService(this.tenant).createVoucher(input);
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
      case 'createPartner':
        return serialize(this.tenant.partnerService.create(input));
      case 'updatePartner':
        return serialize(this.tenant.partnerService.update(input));
      case 'acquireAsset':
        return this.tenant.assetService.acquire(input);
      case 'disposeAsset':
        return this.tenant.assetService.dispose(input);
      case 'runDepreciation':
        return this.tenant.assetService.runDepreciation(input);
      case 'allocate': {
        // Largest-remainder distribution (Money.allocate), scale from the tenant currency
        // (pack parameter currencyScale). Pure computation, no journal effect.
        const totalRaw = input.total;
        const amount =
          totalRaw !== null && typeof totalRaw === 'object' && !Array.isArray(totalRaw)
            ? (totalRaw as Record<string, unknown>).amount
            : null;
        const total = Money.of(typeof amount === 'string' ? amount : '', this.tenant.baseCurrency);
        const weights = Array.isArray(input.weights) ? (input.weights as Array<number | string>) : [];
        const parts = total.allocate(...weights);
        return { parts: parts.map((part) => part.toJSON()), total: total.toJSON() };
      }
      case 'setAllocationScheme':
        return this.tenant.costing.setAllocationScheme(input);
      case 'runCosting': {
        const run = this.tenant.costing.run(input);
        return { runId: run.id.value, status: run.status(), version: run.version };
      }
      case 'releaseCosting': {
        const released = this.tenant.costing.release(input);
        return { runId: released.id.value, status: released.status() };
      }
      default:
        throw new DomainError('E_NOT_IMPLEMENTED', `Operation "${op}" is not defined`);
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
      case 'assetRegister':
        return new AssetRegisterProjection(tenant.assets).compute(params);
      case 'costAllocationSheet':
        return tenant.costing.costAllocationSheet(params);
      case 'ecSalesList':
        return new EcSalesListProjection(
          tenant.baseCurrency,
          tenant.journal,
          tenant.vouchers,
          tenant.partners,
          tenant.tax.registryHandle(),
        ).compute(params);
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
      case 'cashBasisReport':
        return new CashBasisProjection(
          tenant.baseCurrency,
          tenant.accounts,
          tenant.journal,
          tenant.openItems,
          tenant.vouchers,
          tenant.fiscalYears,
          tenant.mappings,
        ).compute(params);
      case 'journalExport':
        return new JournalExportProjection(
          tenant.id,
          tenant.name,
          tenant.baseCurrency,
          tenant.journal,
          tenant.accounts,
          tenant.vouchers,
          tenant.partners,
          tenant.audit,
          tenant.clock,
        ).compute(params);
      case 'datevExport':
        return new DatevExportProjection(
          tenant.journal,
          tenant.accounts,
          tenant.vouchers,
          tenant.partners,
          tenant.tax.registryHandle(),
        ).compute(params);
      case 'auditDataExport':
        return new AuditDataExportProjection(
          tenant.baseCurrency,
          tenant.journal,
          tenant.accounts,
          tenant.vouchers,
        ).compute(params);
      default:
        throw new DomainError('E_NOT_IMPLEMENTED', `Projection "${name}" is not defined`);
    }
  }
}
