export type FixtureStatus = 'pass' | 'fail' | 'crash';

/** Result of a step/projection in the trace (for the double run). */
export interface TraceEntry {
  readonly step?: string;
  readonly projection?: string;
  readonly outcome: Record<string, unknown>;
}

export interface FixtureResult {
  readonly fixture: string;
  readonly status: FixtureStatus;
  /** Deviations (status 'fail'). */
  readonly diffs: string[];
  /** Crash reason (status 'crash'). */
  readonly crashReason?: string;
  /** Execution trace — basis of the determinism double run. */
  readonly trace: TraceEntry[];
}

export interface SuiteResult {
  readonly results: FixtureResult[];
  /** Fixtures whose second run yielded a different trace. */
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
