/**
 * The runner's subject under test: an implementation of the specification. The runner
 * knows only this interface — the core fills it slice by slice.
 *
 * Domain errors (error catalog) are thrown as SubjectError with the exact E_*
 * code; everything else counts as a crash of the implementation.
 */
export interface Subject {
  /**
   * Build a fresh in-memory tenant from the setup block. Placeholders ($V1, …)
   * have already been replaced by concrete UUIDs at this point.
   */
  setup(setup: Record<string, unknown>): void;

  /** Execute a write operation (steps[].op); result per api.md. */
  execute(op: string, input: Record<string, unknown>): Record<string, unknown>;

  /** Compute a projection (reading, deterministic). */
  project(name: string, params: Record<string, unknown>): Record<string, unknown>;
}

export interface SubjectFactory {
  create(): Subject;
}

/**
 * Domain error with catalog code (fehlerkatalog.md). The runner compares
 * the code exactly against expect.error.
 */
export class SubjectError extends Error {
  constructor(
    readonly errorCode: string,
    message = '',
  ) {
    super(message !== '' ? message : errorCode);
    this.name = 'SubjectError';
  }
}
