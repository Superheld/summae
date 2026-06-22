import { InvalidValue } from './errors.js';

/**
 * Currency per ISO 4217 with a fixed decimal-places scale
 * (datenformat.md: "fixed decimal places per currency").
 *
 * Scales deviating from the default 2 — deliberately small, v1 is EUR-centric,
 * foreign currency comes only in v2.
 */
const SCALES: Readonly<Record<string, number>> = {
  JPY: 0,
  KRW: 0,
  BHD: 3,
  KWD: 3,
  TND: 3,
};

export class Currency {
  private constructor(
    readonly code: string,
    readonly scale: number,
  ) {}

  /**
   * `scaleOverride` sets the decimal-places scale explicitly (pack parameter
   * `packPolicy.currencyScale`) — overrides the global default/ISO scale per
   * tenant (jurisdiction-free substrate: scale is a pack matter, not global).
   */
  static of(code: string, scaleOverride?: number): Currency {
    if (!/^[A-Z]{3}$/.test(code)) {
      throw new InvalidValue(`Invalid ISO 4217 code: "${code}"`);
    }
    return new Currency(code, scaleOverride ?? SCALES[code] ?? 2);
  }

  equals(other: Currency): boolean {
    return this.code === other.code;
  }

  toJSON(): string {
    return this.code;
  }

  toString(): string {
    return this.code;
  }
}
