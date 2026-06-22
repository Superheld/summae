import { InvalidValue } from './errors.js';

/**
 * Kontonummer als String — führende Nullen signifikant (datenformat.md).
 * Vergleich nach Unicode-Codepoints, keine Locale-Collation: "10" < "9",
 * "0420" < "1200" < "8400" (determinismus.md §3). JS-`<` auf Strings
 * vergleicht UTF-16-Code-Units = Codepoints im BMP.
 */
export class AccountNumber {
  private constructor(readonly value: string) {}

  static of(value: string): AccountNumber {
    if (value === '' || value.length > 64) {
      throw new InvalidValue('Kontonummer muss 1-64 Zeichen lang sein');
    }
    if (!/^[^\s\p{C}]+$/u.test(value)) {
      throw new InvalidValue(`Kontonummer enthält Whitespace oder Steuerzeichen: "${value}"`);
    }
    return new AccountNumber(value);
  }

  compareTo(other: AccountNumber): number {
    return this.value < other.value ? -1 : this.value > other.value ? 1 : 0;
  }

  equals(other: AccountNumber): boolean {
    return this.value === other.value;
  }

  toJSON(): string {
    return this.value;
  }

  toString(): string {
    return this.value;
  }
}
