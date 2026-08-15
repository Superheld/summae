import { existsSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  Currency,
  DimensionRegistry,
  DomainError,
  type DimensionRuleData,
  type DimensionTypeData,
  type DimensionValueData,
  MappingRegistry,
  SystemClock,
  TaxCodeRegistry,
  TaxProfile,
  type Tenant,
  Uuid,
  UuidV7IdGenerator,
} from '@superheld/summae-core';
import { DatabaseTenantFactory, SyncDb, installSchema } from '@superheld/summae-knex';

const CONFIG_FILE = 'summae.json';
const DB_FILE = 'summae.sqlite';

function isRecord(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}
function recordList(v: unknown): Record<string, unknown>[] {
  return Array.isArray(v) ? v.filter(isRecord) : [];
}

/**
 * CLI workspace: `summae.json` (tenant meta + pack data,
 * app layer) + `summae.sqlite` (persistence via @superheld/summae-knex).
 * Each invocation loads the tenant, runs, the database persists.
 * Counterpart to PHP's `Summae\Cli\Workspace`.
 */

/**
 * A workspace file is a contract, not a suggestion: a missing or unusable field is reported rather
 * than replaced by something plausible.
 */
function requireString(config: Record<string, unknown>, field: string): string {
  const value = config[field];
  if (typeof value !== 'string' || value === '') {
    throw new DomainError('E_WORKSPACE_INVALID', `summae.json: "${field}" is missing or not a string`, { field });
  }
  return value;
}

function parseTenantId(raw: string): Uuid {
  try {
    return Uuid.fromString(raw);
  } catch {
    throw new DomainError('E_WORKSPACE_INVALID', 'summae.json: "tenantId" is not a UUID', { field: 'tenantId' });
  }
}

export class Workspace {
  private constructor(private readonly directory: string) {}

  static in(directory: string): Workspace {
    return new Workspace(directory.replace(/\/+$/, ''));
  }

  exists(): boolean {
    return existsSync(this.configPath());
  }

  /** @param ruleData accounts, taxCodes, taxProfile, dimensionTypes/-Values, ruleModules */
  initialize(name: string, currency: string, ruleData: Record<string, unknown>): void {
    if (this.exists()) {
      throw new Error(`Workspace already exists: ${this.configPath()}`);
    }
    const config = { name, baseCurrency: currency, tenantId: Uuid.v7().value, rules: ruleData };
    writeFileSync(this.configPath(), `${JSON.stringify(config, null, 2)}\n`);

    const db = new SyncDb(this.dbPath());
    installSchema(db);
    db.close();
  }

  /**
   * Remove what `initialize` wrote. Used when creating master data fails afterwards: a workspace
   * that exists but could not be filled is worse than none, because `init` refuses to run again.
   */
  discard(): void {
    for (const path of [this.configPath(), this.dbPath()]) {
      if (existsSync(path)) rmSync(path);
    }
  }

  tenant(): Tenant {
    if (!this.exists()) {
      throw new Error(`No workspace in ${this.directory} — run \`summae init\` first`);
    }
    const config = JSON.parse(readFileSync(this.configPath(), 'utf8')) as Record<string, unknown>;

    // Every field used to fall back to a default and the tenantId was regenerated when missing,
    // so a damaged-but-parseable config opened the same database under a different identity and
    // reported an empty ledger — indistinguishable from books that were never written.
    const tenantId = requireString(config, 'tenantId');
    const name = requireString(config, 'name');
    const baseCurrency = requireString(config, 'baseCurrency');
    const rules = isRecord(config.rules) ? config.rules : {};
    const ruleModules = isRecord(rules.ruleModules) ? rules.ruleModules : {};

    const dimensionTypes: DimensionTypeData[] = recordList(rules.dimensionTypes).map((t) => ({ code: String(t.code) }));
    const dimensionValues: DimensionValueData[] = recordList(rules.dimensionValues).map((v) => ({
      typeCode: String(v.typeCode),
      code: String(v.code),
    }));
    const dimensionRules: DimensionRuleData[] = recordList(ruleModules.dimensionRules).map((r) => {
      const range = isRecord(r.accountRange) ? r.accountRange : {};
      return { accountRange: { from: String(range.from), to: String(range.to) }, requiredDimension: String(r.requiredDimension) };
    });

    const clock = new SystemClock();

    const tenant = DatabaseTenantFactory.build(
      new SyncDb(this.dbPath()),
      name,
      Currency.of(baseCurrency),
      clock,
      new UuidV7IdGenerator(clock),
      {
        dimensions: DimensionRegistry.fromData(dimensionTypes, dimensionValues, dimensionRules),
        taxCodes: TaxCodeRegistry.fromData(recordList(rules.taxCodes)),
        taxProfile: TaxProfile.fromData(isRecord(rules.taxProfile) ? rules.taxProfile : {}),
        mappings: MappingRegistry.fromRuleModules(Array.isArray(ruleModules.mappings) ? ruleModules.mappings : []),
        tenantId: parseTenantId(tenantId),
      },
    );
    tenant.assetService.setRuleModule(ruleModules);
    return tenant;
  }

  private configPath(): string {
    return join(this.directory, CONFIG_FILE);
  }
  private dbPath(): string {
    return join(this.directory, DB_FILE);
  }
}
