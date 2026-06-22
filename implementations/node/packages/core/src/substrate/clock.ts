/**
 * Time source of the core (determinism hook). Deliberately a dedicated interface,
 * PSR-20-/Temporal-compatible in spirit: returns a point in time.
 */
export interface Clock {
  now(): Date;
}

/** Real system time (production). */
export class SystemClock implements Clock {
  now(): Date {
    return new Date();
  }
}

/** Fixed time for tests and deterministic runs. */
export class FixedClock implements Clock {
  private current: Date;

  private constructor(at: Date) {
    this.current = at;
  }

  static at(iso8601: string): FixedClock {
    return new FixedClock(new Date(iso8601));
  }

  now(): Date {
    return this.current;
  }

  advanceMilliseconds(milliseconds: number): void {
    this.current = new Date(this.current.getTime() + milliseconds);
  }
}
