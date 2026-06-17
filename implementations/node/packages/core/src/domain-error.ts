/**
 * Fachlicher Fehler mit Katalog-Code (fehlerkatalog.md). Vertragsteil: gleicher
 * Verstoß → gleicher Code in allen Implementierungen. `message` ist frei,
 * `details` trägt beteiligte IDs/Werte.
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
