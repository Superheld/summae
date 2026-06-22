import { InvalidValue } from './errors.js';

/**
 * Additional allocation of a posting line: dimension type + value code
 * (datenformat.md). The validity check against master data happens at the
 * operation (E_DIMENSION_INVALID), not here.
 */
export class DimensionValue {
  private constructor(
    readonly type: string,
    readonly code: string,
  ) {}

  static of(type: string, code: string): DimensionValue {
    if (type === '' || code === '') {
      throw new InvalidValue('Dimension type and code must not be empty');
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
