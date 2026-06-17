import {
  Account,
  AccountNumber,
  CalendarDate,
  Currency,
  DeterministicIdGenerator,
  DimensionRegistry,
  DomainError,
  FixedClock,
  FiscalYear,
  type PeriodDefinition,
  TaxCodeRegistry,
  TaxProfile,
  Tenant,
  TenantOperations,
  Uuid,
  Voucher,
  isAccountType,
} from '@summae/core';
import { type Subject, SubjectError } from '../subject.js';

// Feste Uhr: recordedAt/at-Zeitstempel sind über beide Suite-Läufe identisch.
const FIXED_NOW = '2026-06-07T12:00:00+02:00';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}
function asRecordList(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? value.filter(isRecord) : [];
}

/**
 * Subject über @summae/core mit In-Memory-Ports. Baut den Mandanten aus dem
 * setup-Block und routet execute/project über TenantOperations; DomainError
 * wird in SubjectError (Katalog-Code) übersetzt.
 */
export class CoreSubject implements Subject {
  private tenant: Tenant | null = null;

  setup(setup: Record<string, unknown>): void {
    const tenantData = isRecord(setup.tenant) ? setup.tenant : null;
    if (tenantData === null) {
      // createTenant-Fixtures (ohne Setup-Mandant) — eigener Slice.
      this.tenant = null;
      return;
    }

    const name = asString(tenantData.name) ?? 'Fixture';
    const currency = Currency.of(asString(tenantData.baseCurrency) ?? 'EUR');
    const clock = FixedClock.at(FIXED_NOW);
    const ids = new DeterministicIdGenerator(clock);

    const ruleModules = isRecord(setup.ruleModules) ? setup.ruleModules : {};
    const dimensionTypes = asRecordList(setup.dimensionTypes).map((t) => ({ code: String(t.code) }));
    const dimensionValues = asRecordList(setup.dimensionValues).map((v) => ({
      typeCode: String(v.typeCode),
      code: String(v.code),
    }));
    const dimensionRules = asRecordList(ruleModules.dimensionRules).map((r) => {
      const range = isRecord(r.accountRange) ? r.accountRange : {};
      return {
        accountRange: { from: String(range.from), to: String(range.to) },
        requiredDimension: String(r.requiredDimension),
      };
    });
    const dimensions =
      dimensionTypes.length || dimensionValues.length || dimensionRules.length
        ? DimensionRegistry.fromData(dimensionTypes, dimensionValues, dimensionRules)
        : DimensionRegistry.empty();

    // taxCodes: top-level oder als Regelmodul; taxProfile: top-level oder am Tenant.
    const taxCodeData = asRecordList(
      Array.isArray(setup.taxCodes) ? setup.taxCodes : ruleModules.taxCodes,
    );
    const taxCodes = TaxCodeRegistry.fromData(taxCodeData);
    const taxProfileData = isRecord(setup.taxProfile)
      ? setup.taxProfile
      : isRecord(tenantData.taxProfile)
        ? tenantData.taxProfile
        : {};
    const taxProfile = TaxProfile.fromData(taxProfileData);

    const tenant = Tenant.inMemory(name, currency, clock, ids, dimensions, taxCodes, taxProfile);

    for (const accountData of asRecordList(setup.accounts)) {
      tenant.accounts.add(this.buildAccount(tenant, accountData));
    }
    for (const fiscalYearData of asRecordList(setup.fiscalYears)) {
      tenant.fiscalYears.add(this.buildFiscalYear(tenant, fiscalYearData));
    }
    for (const voucherData of asRecordList(setup.vouchers)) {
      tenant.vouchers.add(this.buildVoucher(voucherData));
    }

    this.tenant = tenant;
  }

  execute(op: string, input: Record<string, unknown>): Record<string, unknown> {
    const tenant = this.requireTenant();
    const { tenant: _ignored, ...rest } = input;
    try {
      return new TenantOperations(tenant).execute(op, rest);
    } catch (error) {
      throw this.translate(error);
    }
  }

  project(name: string, params: Record<string, unknown>): Record<string, unknown> {
    const tenant = this.requireTenant();
    const { tenant: _ignored, ...rest } = params;
    try {
      return new TenantOperations(tenant).project(name, rest);
    } catch (error) {
      throw this.translate(error);
    }
  }

  private requireTenant(): Tenant {
    if (this.tenant === null) {
      throw new SubjectError('E_NOT_IMPLEMENTED', 'Kein Mandant vorhanden (createTenant folgt im eigenen Slice)');
    }
    return this.tenant;
  }

  private translate(error: unknown): SubjectError {
    if (error instanceof DomainError) {
      return new SubjectError(error.errorCode, error.message);
    }
    if (error instanceof SubjectError) {
      return error;
    }
    throw error;
  }

  private buildAccount(tenant: Tenant, data: Record<string, unknown>): Account {
    const number = asString(data.number) ?? '';
    const name = asString(data.name) ?? '';
    const type = data.type;
    if (!isAccountType(type)) {
      throw new SubjectError('E_COA_FORMAT_INVALID', `Ungültiger Kontotyp im Setup: ${String(type)}`);
    }
    const subtype = asString(data.subtype);
    const status = data.status === 'locked' ? 'locked' : 'active';
    return new Account(tenant.ids.next(), AccountNumber.of(number), name, type, subtype, status);
  }

  private buildFiscalYear(tenant: Tenant, data: Record<string, unknown>): FiscalYear {
    const year = typeof data.year === 'number' ? data.year : 0;
    const start = CalendarDate.of(asString(data.start) ?? '');
    const end = CalendarDate.of(asString(data.end) ?? '');

    let explicitPeriods: PeriodDefinition[] | null = null;
    if (Array.isArray(data.periods)) {
      explicitPeriods = data.periods.filter(isRecord).map((periodData) => ({
        period: typeof periodData.period === 'number' ? periodData.period : 0,
        start: CalendarDate.of(asString(periodData.start) ?? ''),
        end: CalendarDate.of(asString(periodData.end) ?? ''),
      }));
    }

    return FiscalYear.create(tenant.ids.next(), year, start, end, explicitPeriods);
  }

  private buildVoucher(data: Record<string, unknown>): Voucher {
    const id = Uuid.fromString(asString(data.id) ?? Uuid.v7().value);
    const servicePeriod = isRecord(data.servicePeriod) ? data.servicePeriod : {};
    const date = (value: unknown): CalendarDate | null =>
      typeof value === 'string' ? CalendarDate.of(value) : null;

    return new Voucher({
      id,
      voucherNumber: asString(data.voucherNumber) ?? '',
      voucherDate: CalendarDate.of(asString(data.voucherDate) ?? ''),
      due: date(data.due),
      recurring: data.recurring === true,
      economicYear: typeof data.economicYear === 'number' ? data.economicYear : null,
      supplierTaxationMethod: asString(data.supplierTaxationMethod),
      serviceDate: date(data.serviceDate),
      servicePeriodFrom: date(servicePeriod.from),
      servicePeriodTo: date(servicePeriod.to),
      kind: asString(data.kind),
      partnerId: typeof data.partnerId === 'string' ? Uuid.fromString(data.partnerId) : null,
      issuer: asString(data.issuer),
    });
  }
}
