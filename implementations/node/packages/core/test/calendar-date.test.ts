import { describe, expect, it } from 'vitest';
import { CalendarDate } from '../src/index.js';

/**
 * `CalendarDate` is substrate: frozen, jurisdiction-free, and bound by the top quality
 * policy — the same string must be accepted or rejected identically in every language.
 *
 * It was not. Node validated through `Date.UTC(year, …)`, which maps years 0–99 onto
 * 1900+year (a JavaScript legacy rule), so `0000-01-01` … `0099-12-31` failed the
 * round-trip check while PHP's `DateTimeImmutable` accepted them. The divergence sat in
 * the layer that is supposed to be the most stable of all, and it surfaced only because
 * a missing `year` parameter made a projection build `0000-01-01`.
 *
 * **The SAME two tables live in the PHP `CalendarDateTest`.** If one language starts
 * accepting or rejecting a value the other does not, that language's test goes red.
 */
const ACCEPTED = [
  '0000-01-01', // year zero — the JS 0–99 quirk used to reject this
  '0001-01-01',
  '0099-12-31',
  '0100-01-01',
  '1900-01-01',
  '2000-02-29', // divisible by 400 → leap
  '2024-02-29', // divisible by 4 → leap
  '2026-01-01',
  '2026-12-31',
  '9999-12-31',
] as const;

const REJECTED = [
  '0000-00-01', // month 0
  '0050-02-29', // year 50 is not a leap year
  '0100-02-29', // divisible by 100, not 400 → not leap
  '1900-02-29', // the classic: not a leap year
  '2026-02-29', // not a leap year
  '2026-02-30',
  '2026-04-31',
  '2026-13-01',
  '2026-00-10',
  '2026-01-00',
  '2026-01-32',
  '26-01-01', // two-digit year
  '2026-1-1', // unpadded
  '2026-01-01T00:00:00Z', // a timestamp is not a calendar date
  '+2026-01-01',
  '2026/01/01',
  '',
  ' 2026-01-01',
] as const;

describe('CalendarDate — cross-language acceptance', () => {
  it.each(ACCEPTED)('accepts %s and round-trips it unchanged', (value) => {
    expect(CalendarDate.of(value).iso).toBe(value);
  });

  it.each(REJECTED)('rejects %s', (value) => {
    expect(() => CalendarDate.of(value)).toThrow();
  });
});

describe('CalendarDate — month arithmetic without the host Date', () => {
  const cases = [
    { from: '0050-02-10', last: '0050-02-28', next: '0050-03-01' }, // in the old 0–99 band
    { from: '2024-02-10', last: '2024-02-29', next: '2024-03-01' }, // leap February
    { from: '2026-02-10', last: '2026-02-28', next: '2026-03-01' },
    { from: '2026-04-05', last: '2026-04-30', next: '2026-05-01' },
    { from: '2026-12-05', last: '2026-12-31', next: '2027-01-01' }, // year rollover
    { from: '9999-11-02', last: '9999-11-30', next: '9999-12-01' },
  ] as const;

  it.each(cases)('$from → last day $last, first of next month $next', ({ from, last, next }) => {
    expect(CalendarDate.of(from).lastDayOfMonth().iso).toBe(last);
    expect(CalendarDate.of(from).firstDayOfNextMonth().iso).toBe(next);
  });
});

describe('CalendarDate — day difference without the host Date', () => {
  // The same table is in the PHP CalendarDateTest. `daysSince` is what makes the
  // finalization deadline observable (F-CORE-027), so a one-day drift between the
  // languages would show up as a different number in the same audit report.
  const cases = [
    { later: '2026-03-16', earlier: '2026-03-16', days: 0 }, // same day
    { later: '2026-03-16', earlier: '2026-03-14', days: 2 },
    { later: '2026-03-16', earlier: '2026-02-01', days: 43 },
    { later: '2026-01-01', earlier: '2025-12-31', days: 1 }, // year boundary
    { later: '2024-03-01', earlier: '2024-02-28', days: 2 }, // across a leap day
    { later: '2023-03-01', earlier: '2023-02-28', days: 1 }, // no leap day
    { later: '2000-03-01', earlier: '2000-02-28', days: 2 }, // 2000 IS a leap year (÷400)
    { later: '1900-03-01', earlier: '1900-02-28', days: 1 }, // 1900 is NOT (÷100, not ÷400)
    { later: '0050-03-01', earlier: '0050-02-01', days: 28 }, // the old 0–99 band
    { later: '2026-01-01', earlier: '0001-01-01', days: 739616 },
    { later: '2026-02-01', earlier: '2026-03-16', days: -43 }, // negative when earlier
  ] as const;

  it.each(cases)('$later minus $earlier = $days', ({ later, earlier, days }) => {
    expect(CalendarDate.of(later).daysSince(CalendarDate.of(earlier))).toBe(days);
  });
});
