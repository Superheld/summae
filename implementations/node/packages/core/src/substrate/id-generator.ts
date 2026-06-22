import { type Clock, SystemClock } from './clock.js';
import { Uuid } from './uuid.js';

/**
 * ID source of the core — port, so that tests and determinism runs can control
 * generation. Production: UUIDv7.
 */
export interface IdGenerator {
  next(): Uuid;
}

/** Production generator: real UUIDv7 from time + random. */
export class UuidV7IdGenerator implements IdGenerator {
  constructor(private readonly clock: Clock = new SystemClock()) {}

  next(): Uuid {
    return Uuid.v7(this.clock);
  }
}

/**
 * UUIDv7-shaped IDs from a fixed clock + counter instead of random — for tests and the
 * double-run determinism check of the conformance suite (stream hashes contain
 * IDs; two runs must be byte-identical).
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
