/** Business-invalid value (amount, weight, code, UUID …). */
export class InvalidValue extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'InvalidValue';
  }
}

/** Different currencies cannot be offset against each other. */
export class CurrencyMismatch extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'CurrencyMismatch';
  }
}
