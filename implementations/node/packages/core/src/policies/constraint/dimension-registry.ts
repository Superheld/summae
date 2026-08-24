import { DomainError } from '../../domain-error.js';
import type { AccountNumber } from '../../substrate/account-number.js';
import type { DimensionValue } from '../../substrate/dimension-value.js';

interface DimensionRule {
  readonly from: string;
  readonly to: string;
  readonly required: string;
}

export interface DimensionTypeData {
  readonly code: string;
}
export interface DimensionValueData {
  readonly typeCode: string;
  readonly code: string;
}
export interface DimensionRuleData {
  readonly accountRange: { from: string; to: string };
  readonly requiredDimension: string;
}

/**
 * Dimension validation: mechanism in the core, contents as rule module data
 * (ledger-modell.md). Types/values are master data; mandatory dimensions come
 * from `ruleModules.dimensionRules`.
 */
export class DimensionRegistry {
  private constructor(
    private readonly types: Set<string>,
    private readonly values: Set<string>,
    private readonly rules: readonly DimensionRule[],
  ) {}

  static empty(): DimensionRegistry {
    return new DimensionRegistry(new Set(), new Set(), []);
  }

  static fromData(
    dimensionTypes: DimensionTypeData[],
    dimensionValues: DimensionValueData[],
    dimensionRules: DimensionRuleData[],
  ): DimensionRegistry {
    const types = new Set(dimensionTypes.map((t) => t.code));
    const values = new Set(dimensionValues.map((v) => `${v.typeCode}:${v.code}`));
    const rules = dimensionRules.map((r) => ({
      from: r.accountRange.from,
      to: r.accountRange.to,
      required: r.requiredDimension,
    }));
    return new DimensionRegistry(types, values, rules);
  }

  /**
   * The same rules with different master data (SPEC-015).
   *
   * Reopening a tenant means combining two sources that are not the same kind of thing: the types
   * and values are the tenant's, and come back from its record; the rules — which accounts may not
   * be posted without a dimension — are the pack's, and come back from the pack. This is what keeps
   * them apart without asking the adapter to know the difference.
   */
  withMasterData(
    dimensionTypes: DimensionTypeData[],
    dimensionValues: DimensionValueData[],
  ): DimensionRegistry {
    return new DimensionRegistry(
      new Set(dimensionTypes.map((type) => type.code)),
      new Set(dimensionValues.map((value) => `${value.typeCode}:${value.code}`)),
      this.rules,
    );
  }

  /**
   * The registry as the data it was built from (SPEC-015) — `fromData(toData(r))` is `r`.
   *
   * Sorted, because this is what gets stored: two runs that declared the same types in a different
   * order must produce the same stored bytes, or the cross-test would compare a set against an
   * ordering accident. Rules are not included: which accounts require a dimension is the pack's
   * answer, not the tenant's, and it comes back from the pack on every open.
   */
  toData(): { types: Array<{ code: string }>; values: Array<{ typeCode: string; code: string }> } {
    const values = [...this.values].sort().map((entry) => {
      const separator = entry.indexOf(':');
      return { typeCode: entry.slice(0, separator), code: entry.slice(separator + 1) };
    });
    return { types: [...this.types].sort().map((code) => ({ code })), values };
  }

  /**
   * Declares a dimension type (a cost centre axis, a project axis, …).
   *
   * Dimension types and values are the tenant's own master data, like accounts — not the pack's,
   * because "Materialstelle" is a fact about one company and not about German law. They were
   * declarable only through the in-memory construction path, which meant a tenant built FROM A PACK
   * had an empty registry and every posting carrying a cost centre was rejected: cost accounting was
   * unreachable on `de`, `us` and `default` alike, and nothing in the packs said so.
   */
  defineType(code: string): void {
    if (code === '') {
      throw new DomainError('E_DIMENSION_INVALID', 'A dimension type needs a code', { type: code });
    }

    if (this.types.has(code)) {
      throw new DomainError('E_DIMENSION_INVALID', `Dimension type "${code}" is already defined`, { type: code });
    }

    this.types.add(code);
  }

  /**
   * Declares a value of an existing type. Refused for an unknown type rather than creating it on the
   * way: a typo in the type would otherwise open a second, near-identical axis in silence.
   */
  defineValue(typeCode: string, code: string): void {
    if (!this.types.has(typeCode)) {
      throw new DomainError('E_DIMENSION_INVALID', `Unknown dimension type "${typeCode}"`, { type: typeCode });
    }

    if (code === '') {
      throw new DomainError('E_DIMENSION_INVALID', 'A dimension value needs a code', { type: typeCode });
    }

    if (this.values.has(`${typeCode}:${code}`)) {
      throw new DomainError(
        'E_DIMENSION_INVALID',
        `Dimension value "${code}" of type "${typeCode}" is already defined`,
        { type: typeCode, code },
      );
    }

    this.values.add(`${typeCode}:${code}`);
  }

  validateLine(account: AccountNumber, dimensions: DimensionValue[]): void {
    for (const dimension of dimensions) {
      if (!this.types.has(dimension.type)) {
        throw new DomainError('E_DIMENSION_INVALID', `Unknown dimension type "${dimension.type}"`, {
          type: dimension.type,
        });
      }
      if (!this.values.has(`${dimension.type}:${dimension.code}`)) {
        throw new DomainError(
          'E_DIMENSION_INVALID',
          `Unknown dimension value "${dimension.code}" for type "${dimension.type}"`,
          { type: dimension.type, code: dimension.code },
        );
      }
    }

    for (const rule of this.rules) {
      const inRange = account.value >= rule.from && account.value <= rule.to;
      if (!inRange) continue;
      if (dimensions.some((d) => d.type === rule.required)) continue;
      throw new DomainError(
        'E_DIMENSION_INVALID',
        `Mandatory dimension "${rule.required}" missing on account ${account.value}`,
        { account: account.value, required: rule.required },
      );
    }
  }
}
