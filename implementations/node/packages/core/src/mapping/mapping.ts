export interface MappingLeaf {
  key: string;
  label: string;
  side: string | null;
  ranges: Array<{ from: string; to: string }>;
  numbers: string[];
  includeNonCash: boolean;
  includesNetIncome: boolean;
  parents: string[];
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/** Trifft ein Blatt eine Kontonummer? (Einzelnummern oder Codepoint-Bereiche.) */
export function leafMatches(leaf: MappingLeaf, accountNumber: string): boolean {
  if (leaf.numbers.includes(accountNumber)) return true;
  for (const range of leaf.ranges) {
    if (accountNumber >= range.from && accountNumber <= range.to) return true;
  }
  return false;
}

/**
 * Gliederungs-Mapping (datenformat.md v0.2): ordnet Konten Positionen zu —
 * gleiche Struktur für Bilanz, GuV, EÜR-Zeilen und VA-Kennzahlen.
 */
export class Mapping {
  constructor(
    readonly id: string,
    readonly kind: string,
    readonly version: string,
    readonly leaves: MappingLeaf[],
  ) {}

  static fromData(data: Record<string, unknown>): Mapping {
    const leaves: MappingLeaf[] = [];
    Mapping.collectLeaves(Array.isArray(data.positions) ? data.positions : [], [], leaves, null);
    return new Mapping(asString(data.id) ?? '', asString(data.kind) ?? '', asString(data.version) ?? '', leaves);
  }

  private static collectLeaves(
    positions: unknown[],
    parents: string[],
    leaves: MappingLeaf[],
    side: string | null,
  ): void {
    for (const position of positions) {
      if (!isRecord(position)) continue;
      const key = asString(position.key) ?? '';
      // side wird am Wurzelknoten gesetzt und an die Blätter vererbt (v0.5/F-007).
      const nodeSide = asString(position.side) ?? side;
      const children = Array.isArray(position.children) ? position.children : [];

      if (children.length > 0) {
        Mapping.collectLeaves(children, [...parents, key], leaves, nodeSide);
        continue;
      }

      const ranges: Array<{ from: string; to: string }> = [];
      const numbers: string[] = [];
      for (const selector of Array.isArray(position.accounts) ? position.accounts : []) {
        if (!isRecord(selector)) continue;
        if (typeof selector.from === 'string' && typeof selector.to === 'string') {
          ranges.push({ from: selector.from, to: selector.to });
        }
        for (const number of Array.isArray(selector.numbers) ? selector.numbers : []) {
          if (typeof number === 'string') numbers.push(number);
        }
      }

      leaves.push({
        key,
        label: asString(position.label) ?? key,
        side: nodeSide,
        ranges,
        numbers,
        includeNonCash: position.includeNonCash === true,
        includesNetIncome: position.includesNetIncome === true,
        parents,
      });
    }
  }

  leafFor(accountNumber: string): MappingLeaf | null {
    for (const leaf of this.leaves) {
      if (leafMatches(leaf, accountNumber)) return leaf;
    }
    return null;
  }
}
