import { InvalidValue } from './errors.js';

/**
 * Währung nach ISO 4217 mit fester Nachkommastellen-Skala
 * (datenformat.md: "feste Nachkommastellen je Währung").
 *
 * Skalen abweichend vom Default 2 — bewusst klein, v1 ist EUR-zentriert,
 * Fremdwährung kommt erst in v2.
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
   * `scaleOverride` setzt die Nachkommastellen-Skala explizit (Pack-Parameter
   * `packPolicy.currencyScale`) — überstimmt die globale Default-/ISO-Skala pro
   * Mandant (jurisdiktionsfreies Substrat: Skala ist Pack-Sache, nicht global).
   */
  static of(code: string, scaleOverride?: number): Currency {
    if (!/^[A-Z]{3}$/.test(code)) {
      throw new InvalidValue(`Ungültiger ISO-4217-Code: "${code}"`);
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
