/**
 * Placeholder mechanics of the fixtures (testsuite/README.md):
 * "$V1", "$E1", … stand for IDs that the implementation generates itself.
 *
 * - In setup/input an unknown placeholder is bound to a fresh ID,
 *   a known one is replaced by its value.
 * - In expect an unknown placeholder is bound to the actual value
 *   (capture), a known one must match exactly.
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
      throw new Error(`Placeholder ${name} is not bound`);
    }
    return value;
  }

  bind(name: string, value: string): void {
    const existing = this.values.get(name);
    if (existing !== undefined && existing !== value) {
      throw new Error(`Placeholder ${name} is already bound to "${existing}"`);
    }
    this.values.set(name, value);
  }

  /**
   * Recursively replaces all placeholders in input data. Unknown ones are
   * bound to a fresh value via `onUnknown`.
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
