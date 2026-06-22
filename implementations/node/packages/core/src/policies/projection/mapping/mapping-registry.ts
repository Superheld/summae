import { Mapping } from './mapping.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** Loaded mappings of a tenant (mutable via importMapping). */
export class MappingRegistry {
  private readonly byIdMap = new Map<string, Mapping>();

  static empty(): MappingRegistry {
    return new MappingRegistry();
  }

  static fromRuleModules(raw: unknown[]): MappingRegistry {
    const registry = new MappingRegistry();
    for (const mappingData of raw) {
      if (isRecord(mappingData)) registry.add(Mapping.fromData(mappingData));
    }
    return registry;
  }

  add(mapping: Mapping): void {
    this.byIdMap.set(mapping.id, mapping);
  }

  byId(id: string): Mapping | null {
    return this.byIdMap.get(id) ?? null;
  }
}
