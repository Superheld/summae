import { InvalidValue } from './errors.js';

/**
 * Zusatzzuordnung einer Buchungsposition: Dimensionstyp + Wert-Code
 * (datenformat.md). Gültigkeitsprüfung gegen Stammdaten passiert an der
 * Operation (E_DIMENSION_INVALID), nicht hier.
 */
export class DimensionValue {
  private constructor(
    readonly type: string,
    readonly code: string,
  ) {}

  static of(type: string, code: string): DimensionValue {
    if (type === '' || code === '') {
      throw new InvalidValue('Dimensionstyp und -code dürfen nicht leer sein');
    }
    return new DimensionValue(type, code);
  }

  equals(other: DimensionValue): boolean {
    return this.type === other.type && this.code === other.code;
  }

  toJSON(): { type: string; code: string } {
    return { type: this.type, code: this.code };
  }
}
