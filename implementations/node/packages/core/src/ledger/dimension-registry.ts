import { DomainError } from '../domain-error.js';
import type { AccountNumber } from '../shared/account-number.js';
import type { DimensionValue } from '../shared/dimension-value.js';

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
 * Dimensions-Validierung: Mechanik im Kern, Inhalte als Regelmodul-Daten
 * (ledger-modell.md). Typen/Werte sind Stammdaten; Pflichtdimensionen kommen
 * aus `ruleModules.dimensionRules`.
 */
export class DimensionRegistry {
  private constructor(
    private readonly types: ReadonlySet<string>,
    private readonly values: ReadonlySet<string>,
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

  validateLine(account: AccountNumber, dimensions: DimensionValue[]): void {
    for (const dimension of dimensions) {
      if (!this.types.has(dimension.type)) {
        throw new DomainError('E_DIMENSION_INVALID', `Unbekannter Dimensionstyp "${dimension.type}"`, {
          type: dimension.type,
        });
      }
      if (!this.values.has(`${dimension.type}:${dimension.code}`)) {
        throw new DomainError(
          'E_DIMENSION_INVALID',
          `Unbekannter Dimensionswert "${dimension.code}" für Typ "${dimension.type}"`,
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
        `Pflichtdimension "${rule.required}" fehlt auf Konto ${account.value}`,
        { account: account.value, required: rule.required },
      );
    }
  }
}
