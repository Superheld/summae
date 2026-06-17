/**
 * Das Prüfobjekt des Runners: eine Implementierung der Spezifikation. Der Runner
 * kennt nur dieses Interface — der Kern füllt es Slice für Slice.
 *
 * Fachliche Fehler (Fehlerkatalog) werden als SubjectError mit exaktem E_*-Code
 * geworfen; alles andere gilt als Crash der Implementierung.
 */
export interface Subject {
  /**
   * Frischen In-Memory-Mandanten aus dem setup-Block bauen. Platzhalter ($V1, …)
   * sind zu diesem Zeitpunkt bereits durch konkrete UUIDs ersetzt.
   */
  setup(setup: Record<string, unknown>): void;

  /** Eine Schreiboperation (steps[].op) ausführen; Ergebnis laut api.md. */
  execute(op: string, input: Record<string, unknown>): Record<string, unknown>;

  /** Eine Projektion berechnen (lesend, deterministisch). */
  project(name: string, params: Record<string, unknown>): Record<string, unknown>;
}

export interface SubjectFactory {
  create(): Subject;
}

/**
 * Fachlicher Fehler mit Katalog-Code (fehlerkatalog.md). Der Runner vergleicht
 * den Code exakt gegen expect.error.
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
