import {
  AccountNumber,
  CalendarDate,
  type Currency,
  DimensionValue,
  DomainError,
  EntryLine,
  Money,
  type Side,
  Uuid,
} from '@superheld/summae-core';

/**
 * (De)serialization of the adapter's JSON documents — the same
 * "published-language" forms as PHP's `Hydrator`. Writing goes through the
 * `toJSON()` of the domain objects; reading is key-based (order
 * irrelevant — the cross-test compares canonical projections, not column bytes).
 */

export function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function encode(data: unknown): string {
  return JSON.stringify(data);
}

export function decode(json: unknown): Record<string, unknown> {
  if (typeof json !== 'string' || json === '') return {};
  const parsed: unknown = JSON.parse(json);
  return isRecord(parsed) ? parsed : {};
}

export function decodeList(json: unknown): Record<string, unknown>[] {
  if (typeof json !== 'string' || json === '') return [];
  const parsed: unknown = JSON.parse(json);
  return Array.isArray(parsed) ? parsed.filter(isRecord) : [];
}

/**
 * The store's amount, on the TENANT's scale — and the scale is the tenant's, not the currency's
 * default (IMPL-040).
 *
 * This used to build the currency from the stored code with no override, so every amount came back
 * on the ISO default. A tenant whose pack sets `currencyScale: 3` therefore read `"107.501"` as an
 * *unrepresentable* amount and threw a raw `InvalidValue` out of the adapter; a tenant at scale 0
 * got `"1234"` silently widened to `"1234.00"`. Nothing noticed because no fixture that re-hydrates
 * money runs at a scale other than 2 — SF-15 passes because both runtimes agree, not because
 * anything verifies the amounts, which is exactly what the finding said.
 */
export function money(data: Record<string, unknown>, currency: Currency): Money {
  // A malformed document must not take the process down mid-read — zero on the tenant's scale is
  // the documented fallback and stays. A PRESENT amount is a different matter: that is a value
  // somebody wrote, and if it is on the wrong scale we say so instead of reshaping it.
  if (typeof data.amount !== 'string') return Money.zero(currency);

  return Money.of(assertScale(data.amount, currency), currency);
}

/**
 * The amount carries EXACTLY the tenant's decimal places, mandatory zeros included — the canonical
 * form `datenformat.md` § Grundsätze 2 requires of the data format, and the one thing the schema's
 * amount pattern deliberately cannot check, because that pattern is context-free (0–4 places) while
 * the scale is a property of the tenant's pack.
 *
 * Both directions run through here. Reading is where it earns its keep: a store written by one
 * runtime at scale 3 and opened by a tenant at scale 2 is the scenario `E_AMOUNT_SCALE_MISMATCH`
 * was declared for, and until now the code was declared and never raised.
 *
 * `E_ENTRY_INVALID_AMOUNT` keeps the API-input side (`core/post-malformed` pins it, and a fixture is
 * append-only): that code judges an amount a *caller* offered, this one judges an amount already in
 * the books.
 */
export function assertScale(amount: string, currency: Currency): string {
  const point = amount.indexOf('.');
  const places = point === -1 ? 0 : amount.length - point - 1;

  if (places !== currency.scale) {
    throw new DomainError(
      'E_AMOUNT_SCALE_MISMATCH',
      `Stored amount "${amount}" has ${places} decimal place(s); ${currency.code} in this tenant requires ` +
        `exactly ${currency.scale} (canonical form, mandatory zeros included)`,
      { amount, expectedScale: currency.scale },
    );
  }

  return amount;
}

/** Posting date zoneless: only the first 10 characters (YYYY-MM-DD). */
export function date(value: unknown): CalendarDate | null {
  return typeof value === 'string' && value !== '' ? CalendarDate.of(value.slice(0, 10)) : null;
}

export function entryLines(lines: Record<string, unknown>[], currency: Currency): EntryLine[] {
  return lines.map((line) => {
    const dimensions = (Array.isArray(line.dimensions) ? line.dimensions : [])
      .filter(isRecord)
      .filter((d) => typeof d.type === 'string' && typeof d.code === 'string')
      .map((d) => DimensionValue.of(String(d.type), String(d.code)));
    const taxTag = isRecord(line.taxTag) ? line.taxTag : null;
    const moneyData = isRecord(line.money) ? line.money : {};
    return new EntryLine(
      Uuid.fromString(typeof line.accountId === 'string' ? line.accountId : ''),
      AccountNumber.of(typeof line.account === 'string' ? line.account : '0'),
      (typeof line.side === 'string' ? line.side : 'debit') as Side,
      money(moneyData, currency),
      dimensions,
      taxTag,
    );
  });
}

/** Required date from a column; throws if empty (should never happen). */
export function requireDate(value: unknown, field: string): CalendarDate {
  const result = date(value);
  if (result === null) throw new Error(`${field} missing in persisted record`);
  return result;
}
