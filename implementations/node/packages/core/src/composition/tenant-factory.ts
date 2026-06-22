import { DomainError } from '../domain-error.js';
import { Account } from '../ledger/account.js';
import { FiscalYear } from '../ledger/fiscal-year.js';
import { isAccountType } from '../ledger/types.js';
import { MappingRegistry } from '../policies/projection/mapping/mapping-registry.js';
import { AccountNumber } from '../substrate/account-number.js';
import { CalendarDate } from '../substrate/calendar-date.js';
import type { Clock } from '../substrate/clock.js';
import { Currency } from '../substrate/currency.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { TaxCodeRegistry } from '../policies/expansion/tax/tax-code-registry.js';
import { TaxProfile } from '../policies/expansion/tax/tax-profile.js';
import { Tenant } from './tenant.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * `createTenant` (SF-01): Mandant per Profil anlegen — sofort buchbar. Profile
 * sind versionierte Regelmodul-Daten; der Mandant pinnt die Version.
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
      throw new DomainError('E_PROFILE_UNKNOWN', `Profil "${profileId}" ist nicht vorhanden`);
    }

    const coaId = asString(profile.chartOfAccounts) ?? '';
    const coa = this.findById('chartsOfAccounts', coaId);
    if (coa === null) {
      throw new DomainError('E_PROFILE_UNKNOWN', `Kontenrahmen "${coaId}" des Profils fehlt`);
    }

    const wantedCodes = Array.isArray(profile.taxCodes) ? profile.taxCodes : [];
    const allTaxCodes = Array.isArray(this.ruleModules.taxCodes) ? this.ruleModules.taxCodes : [];
    const taxCodeData = allTaxCodes.filter(
      (code): code is Record<string, unknown> => isRecord(code) && wantedCodes.includes(code.code),
    );

    const defaults = isRecord(profile.defaults) ? profile.defaults : {};
    const taxProfile = TaxProfile.fromData(defaults);

    // packPolicy.currencyScale ist ein Pack-Parameter: er bestimmt die Geld-Skala
    // des Mandanten (jurisdiktionsfrei), nicht die globale ISO-Default-Skala.
    const packPolicy = isRecord(this.ruleModules.packPolicy) ? this.ruleModules.packPolicy : null;
    const currencyScale =
      packPolicy !== null && typeof packPolicy.currencyScale === 'number' ? packPolicy.currencyScale : undefined;
    const taxRoundingGranularity =
      packPolicy !== null && typeof packPolicy.taxRoundingGranularity === 'string'
        ? packPolicy.taxRoundingGranularity
        : undefined;

    // Mappings (Bilanz/GuV/EÜR) aus dem aufgelösten Pack in die Registry des Mandanten —
    // sonst finden balanceSheet/incomeStatement die Mappings nicht (Pack-Pfad-Parität zum Inline-Pfad).
    const mappings = MappingRegistry.fromRuleModules(
      Array.isArray(this.ruleModules.mappings) ? this.ruleModules.mappings : [],
    );

    const tenant = Tenant.inMemory(
      asString(input.name) ?? 'Tenant',
      Currency.of(asString(input.baseCurrency) ?? 'EUR', currencyScale),
      this.clock,
      this.ids,
      undefined,
      TaxCodeRegistry.fromData(taxCodeData),
      taxProfile,
      mappings,
      taxRoundingGranularity,
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

    // Asset-/AfA-Regeln aus dem Pack (assetAccounts, depreciation) — Parität zum Inline-Pfad.
    tenant.assetService.setRuleModule(this.ruleModules);

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

  private findById(module: string, id: string): Record<string, unknown> | null {
    const list = Array.isArray(this.ruleModules[module]) ? (this.ruleModules[module] as unknown[]) : [];
    for (const candidate of list) {
      if (isRecord(candidate) && candidate.id === id) return candidate;
    }
    return null;
  }
}
