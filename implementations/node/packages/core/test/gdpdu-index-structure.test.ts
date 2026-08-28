import { describe, expect, it } from 'vitest';
import { Currency, DeterministicIdGenerator, FixedClock, Tenant, TenantOperations } from '../src/index.js';

/**
 * The `index.xml` obeys the element ORDER the DTD fixes (F-IO-012).
 *
 * `io/gdpdu-data-carrier` pins the exact bytes, which guards against drift and proves both languages
 * agree — but a pinned string says nothing about *why* those bytes are right, and a successor fixture
 * could pin a wrong order just as happily. This test states the DTD's content models as the
 * assertions they are, so the reason survives in the repository rather than only in a memo.
 *
 * Initial conformance was established differently and once: on 2026-08-28 this output was validated
 * against the published DTD of the Beschreibungsstandard 1.6, with a negative control — a `Table`
 * whose `Name` and `Description` are swapped is rejected by the validator, so "valid" meant
 * something. What is reproduced here is the part of that DTD this exporter actually exercises.
 *
 * A note for whoever validates by hand and gets a surprise: a strict validator also reports
 * *"Content model of Media is not determinist"*. That is a complaint about the **standard's own**
 * DTD — `(Name, Command*, Table*, Command*, AcceptNoTables?)` is ambiguous under XML 1.0 §3.2.1 — not
 * about this document, and it does not make the file invalid.
 *
 * PHP twin: `GdpduIndexStructureTest`.
 */
function indexXml(): string[] {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = Tenant.inMemory('Prüfer GmbH', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
  const ops = new TenantOperations(tenant);
  ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
  ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
  const result = ops.project('gdpduExport', {}) as { indexXml: string };
  return result.indexXml.split('\n').map((line) => line.trim());
}

/** The element names in document order, ignoring indentation and content. */
function openTags(lines: readonly string[]): string[] {
  return lines.flatMap((line) => {
    const match = /^<([A-Za-z0-9]+)/.exec(line);
    return match === null ? [] : [match[1] as string];
  });
}

function firstIndexOf(tags: readonly string[], name: string): number {
  return tags.indexOf(name);
}

describe('the GDPdU index.xml follows the DTD', () => {
  it('declares the document type and the version the exporter was written against', () => {
    const lines = indexXml();
    expect(lines[0]).toBe('<?xml version="1.0" encoding="utf-8" standalone="no"?>');
    expect(lines[1]).toBe('<!DOCTYPE DataSet SYSTEM "gdpdu-01-03-2019.dtd">');
  });

  it('DataSet is (…, Version, DataSupplier?, …, Media+)', () => {
    const tags = openTags(indexXml());
    expect(tags[0]).toBe('DataSet');
    expect(firstIndexOf(tags, 'Version')).toBeLessThan(firstIndexOf(tags, 'DataSupplier'));
    expect(firstIndexOf(tags, 'DataSupplier')).toBeLessThan(firstIndexOf(tags, 'Media'));
  });

  it('DataSupplier is (Name, Location, Comment) — all three, in that order', () => {
    const lines = indexXml();
    const start = lines.findIndex((line) => line === '<DataSupplier>');
    const end = lines.findIndex((line) => line === '</DataSupplier>');
    expect(start).toBeGreaterThan(-1);
    expect(openTags(lines.slice(start + 1, end))).toEqual(['Name', 'Location', 'Comment']);
  });

  it('Media is (Name, Table*) — the name before any table', () => {
    const lines = indexXml();
    const media = lines.findIndex((line) => line === '<Media>');
    expect(lines[media + 1]).toMatch(/^<Name>/);
  });

  /**
   * `Table (URL, Name?, Description?, Validity?, codepage?, (DecimalSymbol, DigitGroupingSymbol)?,
   * SkipNumBytes?, Range?, Epoch?, (VariableLength | FixedLength))` — the order an importer rejects
   * the file over.
   */
  it('every Table opens URL → Name → Description → codepage → symbols → VariableLength', () => {
    const lines = indexXml();
    for (const [index, line] of lines.entries()) {
      if (line !== '<Table>') continue;
      expect(openTags(lines.slice(index + 1, index + 8))).toEqual([
        'URL',
        'Name',
        'Description',
        'UTF8',
        'DecimalSymbol',
        'DigitGroupingSymbol',
        'VariableLength',
      ]);
    }
  });

  it('VariableLength puts the delimiters first and every primary key before the ordinary columns', () => {
    const lines = indexXml();
    const start = lines.findIndex((line) => line === '<VariableLength>');
    const end = lines.findIndex((line) => line === '</VariableLength>');
    const tags = openTags(lines.slice(start + 1, end)).filter((tag) =>
      ['ColumnDelimiter', 'RecordDelimiter', 'TextEncapsulator', 'VariablePrimaryKey', 'VariableColumn', 'ForeignKey'].includes(tag),
    );
    expect(tags.slice(0, 3)).toEqual(['ColumnDelimiter', 'RecordDelimiter', 'TextEncapsulator']);

    const lastKey = tags.lastIndexOf('VariablePrimaryKey');
    const firstColumn = tags.indexOf('VariableColumn');
    expect(lastKey, 'a table without a primary key would leave the importer unable to join').toBeGreaterThan(-1);
    expect(lastKey).toBeLessThan(firstColumn);
  });

  it('a column is (Name, Description, one type) and a date names its format explicitly', () => {
    const lines = indexXml();
    const start = lines.findIndex((line) => line === '<VariableColumn>');
    expect(openTags(lines.slice(start + 1, start + 3))).toEqual(['Name', 'Description']);
    // The standard's default is DD.MM.YYYY; summae writes ISO everywhere, so it must say so.
    expect(lines.some((line) => line === '<Format>YYYY-MM-DD</Format>')).toBe(true);
  });

  it('a ForeignKey is (Name+, References) and comes after the columns', () => {
    const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
    const tenant = Tenant.inMemory('Prüfer GmbH', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
    const ops = new TenantOperations(tenant);
    ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
    ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
    const lines = ((ops.project('gdpduExport', {}) as { indexXml: string }).indexXml)
      .split('\n')
      .map((line) => line.trim());

    const start = lines.findIndex((line) => line === '<ForeignKey>');
    expect(start, 'journal.csv declares foreign keys to accounts and vouchers').toBeGreaterThan(-1);
    const end = lines.indexOf('</ForeignKey>', start);
    const tags = openTags(lines.slice(start + 1, end));
    expect(tags.at(-1)).toBe('References');
    expect(tags.slice(0, -1).every((tag) => tag === 'Name')).toBe(true);
    expect(start).toBeGreaterThan(lines.findIndex((line) => line === '<VariableColumn>'));
  });
});
