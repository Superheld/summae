import { randomBytes } from 'node:crypto';
import { type Clock, SystemClock } from './clock.js';
import { InvalidValue } from './errors.js';

/**
 * UUIDv7 (RFC 9562): 48 Bit Unix-Millisekunden + Zufall — zeitlich sortierbar
 * als String, implementierungsunabhängig erzeugbar (datenformat.md Grundsatz 3).
 * Fixtures vergleichen nie ID-Werte, nur Platzhalter-Gleichheit.
 */
const PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

export class Uuid {
  private constructor(readonly value: string) {}

  static fromString(value: string): Uuid {
    const normalized = value.toLowerCase();
    if (!PATTERN.test(normalized)) {
      throw new InvalidValue(`Keine gültige UUID: "${value}"`);
    }
    return new Uuid(normalized);
  }

  static v7(clock: Clock = new SystemClock()): Uuid {
    const time = clock.now().getTime().toString(16).padStart(12, '0');
    const random = randomBytes(10).toString('hex'); // 20 Hex-Zeichen Entropie
    // Variantennibble: oberste zwei Bits = 10 -> 8, 9, a oder b.
    const variant = ((parseInt(random[3]!, 16) & 0x3) | 0x8).toString(16);

    return new Uuid(
      `${time.slice(0, 8)}-${time.slice(8, 12)}-7${random.slice(0, 3)}-${variant}${random.slice(4, 7)}-${random.slice(7, 19)}`,
    );
  }

  equals(other: Uuid): boolean {
    return this.value === other.value;
  }

  /** Byteweise = zeitliche Ordnung bei v7. */
  compareTo(other: Uuid): number {
    return this.value < other.value ? -1 : this.value > other.value ? 1 : 0;
  }

  toJSON(): string {
    return this.value;
  }

  toString(): string {
    return this.value;
  }
}
