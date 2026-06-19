import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  Currency,
  DimensionRegistry,
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
 * CLI-Arbeitsbereich: `summae.json` (Mandanten-Meta + Regelmodul-Daten,
 * App-Schicht) + `summae.sqlite` (Persistenz via @superheld/summae-knex).
 * Jeder Aufruf lädt den Mandanten, führt aus, die Datenbank persistiert.
 * Pendant zu PHPs `Summae\Cli\Workspace`.
 */
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
      throw new Error(`Arbeitsbereich existiert bereits: ${this.configPath()}`);
    }
    const config = { name, baseCurrency: currency, tenantId: Uuid.v7().value, rules: ruleData };
    writeFileSync(this.configPath(), `${JSON.stringify(config, null, 2)}\n`);

    const db = new SyncDb(this.dbPath());
    installSchema(db);
    db.close();
  }

  tenant(): Tenant {
    if (!this.exists()) {
      throw new Error(`Kein Arbeitsbereich in ${this.directory} — zuerst \`summae init\` ausführen`);
    }
    const config = JSON.parse(readFileSync(this.configPath(), 'utf8')) as Record<string, unknown>;
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
    const tenantId = typeof config.tenantId === 'string' ? Uuid.fromString(config.tenantId) : undefined;

    const tenant = DatabaseTenantFactory.build(
      new SyncDb(this.dbPath()),
      typeof config.name === 'string' ? config.name : 'CLI',
      Currency.of(typeof config.baseCurrency === 'string' ? config.baseCurrency : 'EUR'),
      clock,
      new UuidV7IdGenerator(clock),
      {
        dimensions: DimensionRegistry.fromData(dimensionTypes, dimensionValues, dimensionRules),
        taxCodes: TaxCodeRegistry.fromData(recordList(rules.taxCodes)),
        taxProfile: TaxProfile.fromData(isRecord(rules.taxProfile) ? rules.taxProfile : {}),
        mappings: MappingRegistry.fromRuleModules(Array.isArray(ruleModules.mappings) ? ruleModules.mappings : []),
        ...(tenantId ? { tenantId } : {}),
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
