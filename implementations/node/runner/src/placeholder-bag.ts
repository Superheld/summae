/**
 * Platzhalter-Mechanik der Fixtures (testsuite/README.md):
 * "$V1", "$E1", … stehen für IDs, die die Implementierung selbst erzeugt.
 *
 * - In setup/input wird ein unbekannter Platzhalter an eine frische ID
 *   gebunden, ein bekannter durch seinen Wert ersetzt.
 * - In expect wird ein unbekannter Platzhalter an den Ist-Wert gebunden
 *   (Capture), ein bekannter muss exakt übereinstimmen.
 */
const PLACEHOLDER = /^\$[A-Za-z0-9_]+$/;

export class PlaceholderBag {
  private readonly values = new Map<string, string>();

  static isPlaceholder(value: unknown): value is string {
    return typeof value === 'string' && PLACEHOLDER.test(value);
  }

  has(name: string): boolean {
    return this.values.has(name);
  }

  get(name: string): string {
    const value = this.values.get(name);
    if (value === undefined) {
      throw new Error(`Platzhalter ${name} ist nicht gebunden`);
    }
    return value;
  }

  bind(name: string, value: string): void {
    const existing = this.values.get(name);
    if (existing !== undefined && existing !== value) {
      throw new Error(`Platzhalter ${name} ist bereits an "${existing}" gebunden`);
    }
    this.values.set(name, value);
  }

  /**
   * Ersetzt rekursiv alle Platzhalter in Eingabedaten. Unbekannte werden über
   * `onUnknown` an einen frischen Wert gebunden.
   */
  resolve(data: unknown, onUnknown: (name: string) => string): unknown {
    if (Array.isArray(data)) {
      return data.map((item) => this.resolve(item, onUnknown));
    }
    if (PlaceholderBag.isPlaceholder(data)) {
      if (!this.has(data)) {
        this.bind(data, onUnknown(data));
      }
      return this.get(data);
    }
    if (data !== null && typeof data === 'object') {
      const out: Record<string, unknown> = {};
      for (const [key, value] of Object.entries(data as Record<string, unknown>)) {
        out[key] = this.resolve(value, onUnknown);
      }
      return out;
    }
    return data;
  }
}
