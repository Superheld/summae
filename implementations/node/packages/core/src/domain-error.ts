/**
 * Domain error with catalog code (fehlerkatalog.md). Contract part: same
 * violation → same code in all implementations. `message` is free-form,
 * `details` carries the IDs/values involved.
 */
export class DomainError extends Error {
  constructor(
    readonly errorCode: string,
    message = '',
    readonly details: Record<string, unknown> = {},
  ) {
    super(message !== '' ? message : errorCode);
    this.name = 'DomainError';
  }
}
