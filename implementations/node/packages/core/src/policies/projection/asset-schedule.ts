import type { AssetRepository } from '../../port.js';
import type { Asset } from '../expansion/assets/asset.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { integerOr } from './parameters.js';

const FIGURES = [
  'openingCost',
  'additions',
  'disposals',
  'transfers',
  'closingCost',
  'openingDepreciation',
  'depreciationOfYear',
  'writeUpsOfYear',
  'depreciationOnDisposals',
  'closingDepreciation',
  'openingBookValue',
  'closingBookValue',
] as const;

type Figure = (typeof FIGURES)[number];

/**
 * The fixed-asset movement schedule (F-CORE-055).
 *
 * **The register answers a different question, and that is the whole reason this exists.**
 * `assetRegister` reports the *stock*: what an asset cost, what has been written off it, what it is
 * worth, at a cutoff date. A movement schedule reports the *year*: what was there at the start, what
 * came in, what went out, what was written off during it, and what is left. Every figure below is
 * already in the journal and in the asset records — the projection that shapes them was simply never
 * written, which is why the row sat in the census as "a projection over data that is all present".
 *
 * **Grouped by asset account, because that is what the statement wants.** A schedule is read
 * alongside the balance sheet, position by position, not asset by asset. Both are reported: the
 * per-asset rows because that is where a figure is checked, and the per-account totals because that
 * is where it is filed.
 *
 * **`transfers` is always `0.00`, and saying why is more useful than leaving the column out.** A
 * statutory schedule has a column for reclassifications between positions. summae has no operation
 * that moves an asset from one account to another, so the column is *structurally* zero rather than
 * unimplemented — a schedule missing the column would be incomplete for whoever files it, and one
 * that silently omitted it would be worse. If a transfer operation ever arrives, this column starts
 * carrying figures and nothing else about the shape changes.
 *
 * **What a disposal does to the year.** The whole accumulated depreciation of a disposed asset
 * leaves with it: it is reported under `onDisposals` and the closing accumulated depreciation is
 * zero. Netting it into the year's depreciation instead would show a year that wrote off less than
 * it did.
 */
export class AssetScheduleProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly assets: AssetRepository,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = integerOr(params.fiscalYear, 0);
    const yearStart = CalendarDate.of(`${String(fiscalYear).padStart(4, '0')}-01-01`);
    const yearEnd = CalendarDate.of(`${String(fiscalYear).padStart(4, '0')}-12-31`);

    const assets = [...this.assets.all()].sort((a, b) => {
      const byAccount = a.assetAccount.value.localeCompare(b.assetAccount.value);
      if (byAccount !== 0) return byAccount;
      const byDate = a.acquiredOn.compareTo(b.acquiredOn);
      return byDate !== 0 ? byDate : a.id.compareTo(b.id);
    });

    const rows: Array<Record<string, unknown>> = [];
    const byAccount = new Map<string, Record<Figure, Money>>();

    for (const asset of assets) {
      // An asset acquired after the year has no line in it at all. One disposed before it has none
      // either — both would otherwise show a row of zeros that a reader has to discount.
      if (asset.acquiredOn.isAfter(yearEnd)) continue;
      const disposedOn = asset.disposedOnDate();
      if (disposedOn !== null && disposedOn.isBefore(yearStart)) continue;

      const figures = this.scheduleFor(asset, yearStart, yearEnd);
      rows.push({
        assetId: asset.id.value,
        name: asset.name,
        account: asset.assetAccount.value,
        ...Object.fromEntries(FIGURES.map((key) => [key, figures[key].amountAsString()])),
      });

      const account = asset.assetAccount.value;
      const group = byAccount.get(account) ?? this.zeroFigures();
      for (const key of FIGURES) group[key] = group[key].add(figures[key]);
      byAccount.set(account, group);
    }

    const totals = this.zeroFigures();
    const groups = [...byAccount.keys()].sort().map((account) => {
      const group = byAccount.get(account) as Record<Figure, Money>;
      for (const key of FIGURES) totals[key] = totals[key].add(group[key]);
      return {
        account,
        ...Object.fromEntries(FIGURES.map((key) => [key, group[key].amountAsString()])),
      };
    });

    return {
      fiscalYear,
      assets: rows,
      byAccount: groups,
      totals: Object.fromEntries(FIGURES.map((key) => [key, totals[key].amountAsString()])),
    };
  }

  private zeroFigures(): Record<Figure, Money> {
    const zero = Money.zero(this.baseCurrency);
    return Object.fromEntries(FIGURES.map((key) => [key, zero])) as Record<Figure, Money>;
  }

  private scheduleFor(asset: Asset, yearStart: CalendarDate, yearEnd: CalendarDate): Record<Figure, Money> {
    const zero = Money.zero(this.baseCurrency);
    const acquiredInYear = !asset.acquiredOn.isBefore(yearStart);
    const disposedOn = asset.disposedOnDate();
    const disposedInYear = disposedOn !== null && !disposedOn.isAfter(yearEnd);

    const openingCost = acquiredInYear ? zero : asset.acquisitionCost;
    const additions = acquiredInYear ? asset.acquisitionCost : zero;
    const disposals = disposedInYear ? asset.acquisitionCost : zero;
    const closingCost = openingCost.add(additions).subtract(disposals);

    const openingDepreciation = acquiredInYear ? zero : asset.accumulatedDepreciationAt(dayBefore(yearStart));

    let depreciationOfYear = zero;
    let writeUpsOfYear = zero;
    for (const booking of asset.depreciationsForPersistence()) {
      const date = CalendarDate.of(booking.date);
      if (date.isBefore(yearStart) || date.isAfter(yearEnd)) continue;

      const amount = Money.of(booking.amount.amount, this.baseCurrency);
      if (booking.kind === 'writeUp') {
        // A write-up is stored as a negative depreciation so every existing reader picks it up; here
        // it is reported positive under its own name, because a schedule that showed it as "less
        // depreciation" would hide a legally distinct event.
        writeUpsOfYear = writeUpsOfYear.add(amount.negate());
        continue;
      }
      depreciationOfYear = depreciationOfYear.add(amount);
    }

    const depreciationOnDisposals = disposedInYear
      ? openingDepreciation.add(depreciationOfYear).subtract(writeUpsOfYear)
      : zero;

    const closingDepreciation = openingDepreciation
      .add(depreciationOfYear)
      .subtract(writeUpsOfYear)
      .subtract(depreciationOnDisposals);

    return {
      openingCost,
      additions,
      disposals,
      // Structurally zero: see the class comment. The column is here so the schedule is complete in
      // shape, not because a transfer could occur and did not.
      transfers: zero,
      closingCost,
      openingDepreciation,
      depreciationOfYear,
      writeUpsOfYear,
      depreciationOnDisposals,
      closingDepreciation,
      openingBookValue: openingCost.subtract(openingDepreciation),
      closingBookValue: closingCost.subtract(closingDepreciation),
    };
  }
}

/**
 * The last day before a fiscal year starts — `accumulatedDepreciationAt` is inclusive, and a
 * schedule's opening figure is what stood there *before* the year, not on its first day.
 */
function dayBefore(date: CalendarDate): CalendarDate {
  const [year, month, day] = date.iso.split('-').map(Number) as [number, number, number];
  const previous = new Date(Date.UTC(year, month - 1, day - 1));
  return CalendarDate.of(previous.toISOString().slice(0, 10));
}
