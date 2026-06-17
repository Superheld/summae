/** Fachlich ungültiger Wert (Betrag, Gewicht, Code, UUID …). */
export class InvalidValue extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'InvalidValue';
  }
}

/** Verschiedene Währungen lassen sich nicht verrechnen. */
export class CurrencyMismatch extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'CurrencyMismatch';
  }
}
