import Big from 'big.js';

// Determinismus-Anhang: kaufmännisch HALF_UP, von Null weg gerundet —
// NICHT banker's rounding. big.js `roundHalfUp` (=1) rundet .5 away-from-zero.
const HALF_UP_AWAY_FROM_ZERO = Big.roundHalfUp;

/**
 * Rundet einen Dezimalwert auf `decimalPlaces` Stellen, half-up away-from-zero,
 * und gibt ihn mit fester Skala als String zurück (deterministische Ausgabe).
 *
 * Seed des künftigen `Money`-Value-Objects (Node-M1, Shared Kernel). Der Wert
 * kommt bewusst als **String** (Published-Language-Format ist String-Dezimal):
 * `Number('2.225')` wäre 2.22499… und ergäbe fälschlich 2.22.
 *
 * Beweist die Lib-Wahl: `2.225 → 2.23` (banker's wäre 2.22).
 */
export function roundHalfUp(value: string, decimalPlaces: number): string {
  return new Big(value).round(decimalPlaces, HALF_UP_AWAY_FROM_ZERO).toFixed(decimalPlaces);
}
