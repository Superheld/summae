import { DomainError } from '../domain-error.js';
import { Account } from '../substrate/account.js';
import { FiscalYear } from '../substrate/fiscal-year.js';
import { isAccountType } from '../substrate/types.js';
import { MappingRegistry } from '../policies/projection/mapping/mapping-registry.js';
import { AccountNumber } from '../substrate/account-number.js';
import { CalendarDate } from '../substrate/calendar-date.js';
import type { Clock } from '../substrate/clock.js';
import { Currency } from '../substrate/currency.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { TaxCodeRegistry } from '../policies/expansion/tax/tax-code-registry.js';
import { TaxProfile } from '../policies/expansion/tax/tax-profile.js';
import { DimensionRegistry, type DimensionRuleData } from '../policies/constraint/dimension-registry.js';
import { Tenant } from './tenant.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * `createTenant` (SF-01): create a tenant from a profile — immediately postable. Profiles
 * are versioned rule-module data; the tenant pins the version.
 */
export class TenantFactory {
  constructor(
    private readonly ruleModules: Record<string, unknown>,
    private readonly clock: Clock,
    private readonly ids: IdGenerator,
  ) {}

  create(input: Record<string, unknown>): { tenant: Tenant; result: Record<string, unknown> } {
    const profileId = asString(input.profile) ?? '';
    const profile = this.findById('profiles', profileId);
    if (profile === null) {
      throw new DomainError('E_PROFILE_UNKNOWN', `Profile "${profileId}" does not exist`);
    }

    const coaId = asString(profile.chartOfAccounts) ?? '';
    const coa = this.findById('chartsOfAccounts', coaId);
    if (coa === null) {
      throw new DomainError('E_PROFILE_UNKNOWN', `Chart of accounts "${coaId}" of the profile is missing`);
    }

    const wantedCodes = Array.isArray(profile.taxCodes) ? profile.taxCodes : [];
    const allTaxCodes = Array.isArray(this.ruleModules.taxCodes) ? this.ruleModules.taxCodes : [];
    const taxCodeData = allTaxCodes.filter(
      (code): code is Record<string, unknown> => isRecord(code) && wantedCodes.includes(code.code),
    );

    const defaults = isRecord(profile.defaults) ? profile.defaults : {};
    // Which filing windows exist is the pack's answer, not the substrate's (SPEC-016). A pack that
    // says nothing gets the substrate's default; one that says something replaces it.
    const policy = isRecord(this.ruleModules.packPolicy) ? this.ruleModules.packPolicy : null;
    const vatPeriods = Array.isArray(policy?.vatPeriods)
      ? policy.vatPeriods.filter((value): value is string => typeof value === 'string')
      : null;
    const taxProfile = TaxProfile.fromData(defaults, vatPeriods);

    // packPolicy.currencyScale is a pack parameter: it sets the tenant's money scale
    // (jurisdiction-free), not the global ISO default scale.
    const packPolicy = isRecord(this.ruleModules.packPolicy) ? this.ruleModules.packPolicy : null;
    const currencyScale =
      packPolicy !== null && typeof packPolicy.currencyScale === 'number' ? packPolicy.currencyScale : undefined;
    const taxRoundingGranularity =
      packPolicy !== null && typeof packPolicy.taxRoundingGranularity === 'string'
        ? packPolicy.taxRoundingGranularity
        : undefined;

    // Mappings (balance sheet/P&L/cash-basis) from the resolved pack into the tenant's registry —
    // otherwise balanceSheet/incomeStatement do not find the mappings (pack-path parity with the inline path).
    const mappings = MappingRegistry.fromRuleModules(
      Array.isArray(this.ruleModules.mappings) ? this.ruleModules.mappings : [],
    );

    // Constraint plugs from the pack. Types and values stay the tenant's own master data
    // (defineDimensionType/Value) — a jurisdiction has no opinion about what a company calls its cost
    // centres — but WHICH ACCOUNTS MAY NOT BE POSTED WITHOUT ONE is a rule a pack can hold, and until
    // now no pack could: the registry was built with nothing at all.
    const dimensionRules = (Array.isArray(this.ruleModules.dimensionRules) ? this.ruleModules.dimensionRules : [])
      .filter((rule): rule is DimensionRuleData => isRecord(rule));

    const tenant = Tenant.inMemory(
      asString(input.name) ?? 'Tenant',
      Currency.of(asString(input.baseCurrency) ?? 'EUR', currencyScale),
      this.clock,
      this.ids,
      DimensionRegistry.fromData([], [], dimensionRules),
      TaxCodeRegistry.fromData(taxCodeData),
      taxProfile,
      mappings,
      taxRoundingGranularity,
      this.packIdentity(),
    );

    let accountCount = 0;
    for (const accountData of Array.isArray(coa.accounts) ? coa.accounts : []) {
      if (!isRecord(accountData)) continue;
      const type = accountData.type;
      if (!isAccountType(type)) continue;
      tenant.accounts.add(
        new Account(
          tenant.ids.next(),
          AccountNumber.of(asString(accountData.number) ?? ''),
          asString(accountData.name) ?? '',
          type,
          asString(accountData.subtype),
          'active',
        ),
      );
      accountCount++;
    }

    const year = typeof input.firstFiscalYear === 'number' ? input.firstFiscalYear : 0;
    if (year > 0) {
      const y = String(year).padStart(4, '0');
      tenant.fiscalYears.add(
        FiscalYear.create(tenant.ids.next(), year, CalendarDate.of(`${y}-01-01`), CalendarDate.of(`${y}-12-31`)),
      );
    }

    // Asset/depreciation rules from the pack (assetAccounts, depreciation) — parity with the inline path.
    tenant.assetService.setRuleModule(this.ruleModules);
    // And the same for costing: the pack decides which components may enter production cost.
    tenant.costing.setRuleModule(this.ruleModules);

    return {
      tenant,
      result: {
        id: tenant.id.value,
        name: tenant.name,
        profile: { id: profileId, version: asString(profile.version) ?? '' },
        accountCount,
        taxationMethod: taxProfile.taxationMethod(),
      },
    };
  }

  /**
   * The pack the resolved bundle came from, when it came from one. An inline bundle has no
   * manifest, so there is nothing to name and the description says so rather than guessing.
   */
  private packIdentity(): { id: string; version: string } | null {
    const pack = isRecord(this.ruleModules.pack) ? this.ruleModules.pack : null;
    if (pack === null) return null;
    const id = asString(pack.id);
    const version = asString(pack.version);
    return id !== null && version !== null ? { id, version } : null;
  }

  private findById(module: string, id: string): Record<string, unknown> | null {
    const list = Array.isArray(this.ruleModules[module]) ? (this.ruleModules[module] as unknown[]) : [];
    for (const candidate of list) {
      if (isRecord(candidate) && candidate.id === id) return candidate;
    }
    return null;
  }
}
