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

  /**
   * Which mappings are in force, by name — what `tenantConfiguration` publishes.
   *
   * Identity only, never the positions. The definitions are the pack's, and the handbook rule that
   * keeps them there is deliberate: the embedding pins and ships the pack, summae takes it on every
   * open and stores no copy, because two answers to "which rules is this tenant on" is one answer
   * too many. Publishing the leaves here would create exactly that second answer.
   *
   * What a caller cannot know without this is the part that is genuinely summae's: which `mapping`
   * names `balanceSheet`, `incomeStatement` and `cashBasisReport` will accept — the pack's, plus
   * whatever `importMapping` layered on top or replaced.
   *
   * Sorted by id, so the answer does not depend on the order the registry was filled in.
   */
  summaries(): Array<{ id: string; kind: string; version: string }> {
    return [...this.byIdMap.values()]
      .map((mapping) => ({ id: mapping.id, kind: mapping.kind, version: mapping.version }))
      .sort((left, right) => (left.id < right.id ? -1 : left.id > right.id ? 1 : 0));
  }
}
