import { InvalidValue } from './errors.js';

/**
 * Account number as a string — leading zeros significant (datenformat.md).
 * Comparison by Unicode code points, no locale collation: "10" < "9",
 * "0420" < "1200" < "8400" (determinismus.md §3). JS `<` on strings
 * compares UTF-16 code units = code points in the BMP.
 */
export class AccountNumber {
  private constructor(readonly value: string) {}

  static of(value: string): AccountNumber {
    if (value === '' || value.length > 64) {
      throw new InvalidValue('Account number must be 1-64 characters long');
    }
    if (!/^[^\s\p{C}]+$/u.test(value)) {
      throw new InvalidValue(`Account number contains whitespace or control characters: "${value}"`);
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
