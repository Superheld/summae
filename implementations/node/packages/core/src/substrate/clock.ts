/**
 * Zeitquelle des Kerns (Determinismus-Hook). Bewusst eigenes Interface,
 * PSR-20-/Temporal-kompatibel im Geist: liefert einen Zeitpunkt.
 */
export interface Clock {
  now(): Date;
}

/** Echte Systemzeit (Produktion). */
export class SystemClock implements Clock {
  now(): Date {
    return new Date();
  }
}

/** Feststehende Zeit für Tests und deterministische Läufe. */
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
