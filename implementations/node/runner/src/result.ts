export type FixtureStatus = 'pass' | 'fail' | 'crash';

/** Ergebnis eines Schritts/einer Projektion in der Spur (für den Doppellauf). */
export interface TraceEntry {
  readonly step?: string;
  readonly projection?: string;
  readonly outcome: Record<string, unknown>;
}

export interface FixtureResult {
  readonly fixture: string;
  readonly status: FixtureStatus;
  /** Abweichungen (status 'fail'). */
  readonly diffs: string[];
  /** Absturzgrund (status 'crash'). */
  readonly crashReason?: string;
  /** Spur der Ausführung — Grundlage des Determinismus-Doppellaufs. */
  readonly trace: TraceEntry[];
}

export interface SuiteResult {
  readonly results: FixtureResult[];
  /** Fixtures, deren zweiter Lauf eine andere Spur lieferte. */
  readonly determinismBreaks: string[];
}

export function passOrFail(
  fixture: string,
  diffs: string[],
  trace: TraceEntry[],
): FixtureResult {
  return { fixture, status: diffs.length === 0 ? 'pass' : 'fail', diffs, trace };
}

export function crashed(fixture: string, reason: string, trace: TraceEntry[]): FixtureResult {
  return { fixture, status: 'crash', diffs: [], crashReason: reason, trace };
}
