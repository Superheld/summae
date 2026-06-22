import {
  Account,
  AccountNumber,
  CalendarDate,
  type Clock,
  Currency,
  DeterministicIdGenerator,
  DimensionRegistry,
  DomainError,
  FixedClock,
  FiscalYear,
  type IdGenerator,
  MappingRegistry,
  type PackManifest,
  type PackModule,
  type PeriodDefinition,
  resolvePack,
  ruleModulesFromResolved,
  TaxCodeRegistry,
  TaxProfile,
  Tenant,
  TenantFactory,
  TenantOperations,
  Uuid,
  Voucher,
  isAccountType,
} from '@superheld/summae-core';
import { type Subject, SubjectError } from '../subject.js';
import { loadPackLibrary, type PackLibrary } from '../pack-library.js';

// Fixed clock: recordedAt/at timestamps are identical across both suite runs.
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
 * Subject over @superheld/summae-core with in-memory ports. Builds the tenant from the
 * setup block and routes execute/project via TenantOperations; DomainError
 * is translated into SubjectError (catalog code).
 */
/**
 * Adapter hook: builds the tenant from the setup block. Default = in-memory;
 * the database subject passes in a builder that wires up DB-backed ports
 * (signature = `Tenant.inMemory`).
 */
export type TenantBuilder = (
  name: string,
  baseCurrency: Currency,
  clock: Clock,
  ids: IdGenerator,
  dimensions: DimensionRegistry,
  taxCodes: TaxCodeRegistry,
  taxProfile: TaxProfile,
  mappings: MappingRegistry,
) => Tenant;

export class CoreSubject implements Subject {
  private tenant: Tenant | null = null;
  private ruleModules: Record<string, unknown> = {};
  private packModules: PackModule[] = [];
  private packManifests: PackManifest[] = [];
  private readonly tenants = new Map<string, Tenant>();

  constructor(
    private readonly buildTenant: TenantBuilder = (
      name,
      baseCurrency,
      clock,
      ids,
      dimensions,
      taxCodes,
      taxProfile,
      mappings,
    ) => Tenant.inMemory(name, baseCurrency, clock, ids, dimensions, taxCodes, taxProfile, mappings),
    // Shipped pack library as an additional module/manifest source.
    // Inline setup takes precedence; if absent, the loader applies (createTenant(pack:"default")).
    private readonly library: PackLibrary = loadPackLibrary(),
  ) {}

  setup(setup: Record<string, unknown>): void {
    // Some fixtures use the singular key `ruleModule`.
    const ruleModules: Record<string, unknown> = {
      ...(isRecord(setup.ruleModules) ? setup.ruleModules : {}),
      ...(isRecord(setup.ruleModule) ? setup.ruleModule : {}),
    };
    this.ruleModules = ruleModules;

    // Pack source (resolver): modules under setup.modules | setup.moduleSource | setup.pack.modules;
    // manifests under setup.manifests + setup.pack.manifest (singular).
    const pack = isRecord(setup.pack) ? setup.pack : null;
    const moduleSource = setup.moduleSource;
    const moduleList = asRecordList(setup.modules).length
      ? asRecordList(setup.modules)
      : isRecord(moduleSource)
        ? asRecordList(moduleSource.modules)
        : Array.isArray(moduleSource)
          ? asRecordList(moduleSource)
          : pack
            ? asRecordList(pack.modules)
            : [];
    // Inline modules/manifests first (precedence in findManifest), then the library.
    this.packModules = [
      ...(moduleList as unknown as PackModule[]),
      ...this.library.modules,
    ];
    this.packManifests = [
      ...(asRecordList(setup.manifests) as unknown as PackManifest[]),
      ...(pack && isRecord(pack.manifest) ? [pack.manifest as unknown as PackManifest] : []),
      ...this.library.manifests,
    ];

    const tenantData = isRecord(setup.tenant) ? setup.tenant : null;
    if (tenantData === null) {
      // No setup tenant (createTenant fixtures): only keep rule modules.
      this.tenant = null;
      return;
    }

    const name = asString(tenantData.name) ?? 'Fixture';
    const currency = Currency.of(asString(tenantData.baseCurrency) ?? 'EUR');
    const clock = FixedClock.at(FIXED_NOW);
    const ids = new DeterministicIdGenerator(clock);
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

    // taxCodes: top-level or as a rule module; taxProfile: top-level or on the tenant.
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

    const mappings = MappingRegistry.fromRuleModules(
      Array.isArray(ruleModules.mappings) ? ruleModules.mappings : [],
    );

    const tenant = this.buildTenant(name, currency, clock, ids, dimensions, taxCodes, taxProfile, mappings);
    tenant.assetService.setRuleModule(ruleModules);

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
    if (op === 'createTenant') {
      try {
        return this.createTenant(input);
      } catch (error) {
        throw this.translate(error);
      }
    }
    if (op === 'resolvePack') {
      try {
        return this.resolvePackOp(input);
      } catch (error) {
        throw this.translate(error);
      }
    }
    const tenant = this.resolveTenant(input);
    const { tenant: _ignored, ...rest } = input;
    try {
      return new TenantOperations(tenant).execute(op, rest);
    } catch (error) {
      throw this.translate(error);
    }
  }

  project(name: string, params: Record<string, unknown>): Record<string, unknown> {
    const tenant = this.resolveTenant(params);
    const { tenant: _ignored, ...rest } = params;
    try {
      return new TenantOperations(tenant).project(name, rest);
    } catch (error) {
      throw this.translate(error);
    }
  }

  private createTenant(input: Record<string, unknown>): Record<string, unknown> {
    const clock = FixedClock.at(FIXED_NOW);
    // Pack path: resolve manifest -> ruleModules -> unchanged factory; in the result
    // `profile` is swapped for `pack` (tenant pins the manifest, api.additions A.1).
    if (typeof input.pack === 'string' || isRecord(input.pack)) {
      const manifest = this.findManifest({ manifest: input.pack });
      const resolved = resolvePack(manifest, this.packModules);
      const factory = new TenantFactory(
        ruleModulesFromResolved(resolved),
        clock,
        new DeterministicIdGenerator(clock),
      );
      const created = factory.create({ ...input, profile: asString(resolved.profile.id) ?? '' });
      this.tenants.set(created.tenant.id.value, created.tenant);
      const { profile: _profile, ...rest } = created.result;
      return { ...rest, pack: { id: resolved.id, version: resolved.version } };
    }
    const factory = new TenantFactory(this.ruleModules, clock, new DeterministicIdGenerator(clock));
    const created = factory.create(input);
    this.tenants.set(created.tenant.id.value, created.tenant);
    return created.result;
  }

  private resolvePackOp(input: Record<string, unknown>): Record<string, unknown> {
    const manifest = this.findManifest(input);
    const resolved = resolvePack(manifest, this.packModules);
    return {
      id: resolved.id,
      version: resolved.version,
      accountCount: resolved.chartOfAccounts.accounts.length,
      chartOfAccounts: resolved.chartOfAccounts,
      taxCodes: resolved.taxCodes,
      mappings: resolved.mappings,
      ...(resolved.assetAccounts !== null ? { assetAccounts: resolved.assetAccounts } : {}),
      ...(resolved.depreciation !== null ? { depreciation: resolved.depreciation } : {}),
      packPolicy: resolved.packPolicy,
    };
  }

  /** Resolve manifest reference: `manifest` as string id (+ `version`) or as `{id, version}`. */
  private findManifest(ref: Record<string, unknown>): PackManifest {
    const raw = ref.manifest;
    const id = isRecord(raw) ? asString(raw.id) : asString(raw);
    const version = isRecord(raw) ? asString(raw.version) : asString(ref.version);
    const found = this.packManifests.find(
      (m) => m.id === id && (version === null || m.version === version),
    );
    if (found === undefined) {
      throw new DomainError('E_PACK_UNRESOLVED_REF', `Manifest not found: ${String(id)}`);
    }
    return found;
  }

  /** Routing: explicit tenant reference, otherwise setup tenant, otherwise last created. */
  private resolveTenant(input: Record<string, unknown>): Tenant {
    const ref = input.tenant;
    if (typeof ref === 'string' && this.tenants.has(ref)) return this.tenants.get(ref)!;
    if (this.tenant !== null) return this.tenant;
    const last = [...this.tenants.values()].at(-1);
    if (last === undefined) throw new SubjectError('E_NOT_IMPLEMENTED', 'No tenant present');
    return last;
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
      throw new SubjectError('E_COA_FORMAT_INVALID', `Invalid account type in setup: ${String(type)}`);
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
