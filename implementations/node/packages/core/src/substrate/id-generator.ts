import { type Clock, SystemClock } from './clock.js';
import { Uuid } from './uuid.js';

/**
 * ID-Quelle des Kerns — Port, damit Tests und Determinismus-Läufe die Erzeugung
 * kontrollieren können. Produktion: UUIDv7.
 */
export interface IdGenerator {
  next(): Uuid;
}

/** Produktions-Generator: echte UUIDv7 aus Zeit + Zufall. */
export class UuidV7IdGenerator implements IdGenerator {
  constructor(private readonly clock: Clock = new SystemClock()) {}

  next(): Uuid {
    return Uuid.v7(this.clock);
  }
}

/**
 * UUIDv7-förmige IDs aus fester Uhr + Zähler statt Zufall — für Tests und den
 * Doppellauf-Determinismus-Check der Konformitätssuite (Strom-Hashes enthalten
 * IDs; zwei Läufe müssen byte-identisch sein).
 */
export class DeterministicIdGenerator implements IdGenerator {
  private counter = 0;

  constructor(private readonly clock: Clock) {}

  next(): Uuid {
    this.counter++;

    const time = this.clock.now().getTime().toString(16).padStart(12, '0');
    const sequence = this.counter.toString(16).padStart(18, '0');

    return Uuid.fromString(
      `${time.slice(0, 8)}-${time.slice(8, 12)}-7${sequence.slice(0, 3)}-8${sequence.slice(3, 6)}-${sequence.slice(6, 18)}`,
    );
  }
}
