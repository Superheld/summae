import {
  AccountNumber,
  CalendarDate,
  DimensionValue,
  EntryLine,
  Money,
  type Side,
  Uuid,
} from '@superheld/summae-core';

/**
 * (De-)Serialisierung der JSON-Dokumente des Adapters — dieselben
 * „Published-Language"-Formen wie PHPs `Hydrator`. Geschrieben wird über die
 * `toJSON()` der Domänenobjekte; gelesen wird schlüsselbasiert (Reihenfolge
 * egal — der Cross-Test vergleicht kanonische Projektionen, nicht Spalten-Bytes).
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

export function money(data: Record<string, unknown>): Money {
  const amount = typeof data.amount === 'string' ? data.amount : '0';
  const currency = typeof data.currency === 'string' ? data.currency : 'EUR';
  return Money.of(amount, currency);
}

/** Buchungsdatum zonenlos: nur die ersten 10 Zeichen (YYYY-MM-DD). */
export function date(value: unknown): CalendarDate | null {
  return typeof value === 'string' && value !== '' ? CalendarDate.of(value.slice(0, 10)) : null;
}

export function entryLines(lines: Record<string, unknown>[]): EntryLine[] {
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
      money(moneyData),
      dimensions,
      taxTag,
    );
  });
}

/** Pflicht-Datum aus einer Spalte; wirft, wenn leer (sollte nie passieren). */
export function requireDate(value: unknown, field: string): CalendarDate {
  const result = date(value);
  if (result === null) throw new Error(`${field} fehlt im persistierten Datensatz`);
  return result;
}
