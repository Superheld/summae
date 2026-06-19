import { DomainError } from '../domain-error.js';
import type { AccountNumber } from '../shared/account-number.js';
import { CalendarDate } from '../shared/calendar-date.js';
import type { Money } from '../shared/money.js';
import type { Uuid } from '../shared/uuid.js';
import type { AssetRoute } from './asset-route.js';

interface Depreciation {
  planMonth: number;
  date: CalendarDate;
  amount: Money;
  entryId: Uuid;
}

/** Letzter Tag des Monats `monthsToAdd` nach `base` (1-basierte Planmonate). */
function lastDayOfMonthAfter(base: CalendarDate, monthsToAdd: number): CalendarDate {
  const totalMonth0 = base.month() - 1 + monthsToAdd;
  const year = base.year() + Math.floor(totalMonth0 / 12);
  const month0 = ((totalMonth0 % 12) + 12) % 12;
  const lastDay = new Date(Date.UTC(year, month0 + 1, 0)).getUTCDate();
  return CalendarDate.of(
    `${String(year).padStart(4, '0')}-${String(month0 + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`,
  );
}

/**
 * Anlagegut (assets-modell.md): Stammdaten + AfA-Plan + Lebenslauf. Invarianten:
 * Restbuchwert = AHK − Σ Abschreibungen, nie < 0; keine AfA vor Zugang/nach Abgang.
 */
export class Asset {
  private readonly depreciations: Depreciation[] = [];
  private disposed = false;
  private disposedOn: CalendarDate | null = null;

  constructor(
    readonly id: Uuid,
    readonly name: string,
    readonly assetClass: string,
    readonly assetAccount: AccountNumber,
    readonly acquisitionCost: Money,
    readonly acquiredOn: CalendarDate,
    readonly route: AssetRoute,
    readonly usefulLifeMonths: number | null,
    readonly monthlySchedule: Money[],
    readonly voucherId: Uuid,
  ) {}

  isDisposed(): boolean {
    return this.disposed;
  }

  assertActive(): void {
    if (this.disposed) {
      throw new DomainError(
        'E_ASSET_DISPOSED',
        `Anlagegut ${this.id.value} ist bereits abgegangen (${this.disposedOn?.iso ?? '?'})`,
        { assetId: this.id.value },
      );
    }
  }

  dispose(disposedOn: CalendarDate): void {
    this.assertActive();
    this.disposed = true;
    this.disposedOn = disposedOn;
  }

  planMonthDate(planMonth: number): CalendarDate {
    return lastDayOfMonthAfter(this.acquiredOn, planMonth - 1);
  }

  isMonthBooked(planMonth: number): boolean {
    return this.depreciations.some((booking) => booking.planMonth === planMonth);
  }

  recordDepreciation(planMonth: number, date: CalendarDate, amount: Money, entryId: Uuid): void {
    this.depreciations.push({ planMonth, date, amount, entryId });
  }

  /** AfA-Lebenslauf in persistierbarer Form — Pendant zu PHPs `depreciationsForPersistence`. */
  depreciationsForPersistence(): Array<{
    planMonth: number;
    date: string;
    amount: { amount: string; currency: string };
    entryId: string;
  }> {
    return this.depreciations.map((booking) => ({
      planMonth: booking.planMonth,
      date: booking.date.iso,
      amount: booking.amount.toJSON(),
      entryId: booking.entryId.value,
    }));
  }

  /**
   * Aus Persistenz wiederherstellen: Stammdaten + AfA-Lebenslauf + Abgangsstatus
   * direkt setzen (keine erneute Prüfung) — Pendant zu PHPs `Asset::restore`.
   */
  static restore(
    id: Uuid,
    name: string,
    assetClass: string,
    assetAccount: AccountNumber,
    acquisitionCost: Money,
    acquiredOn: CalendarDate,
    route: AssetRoute,
    usefulLifeMonths: number | null,
    monthlySchedule: Money[],
    voucherId: Uuid,
    depreciations: ReadonlyArray<{ planMonth: number; date: CalendarDate; amount: Money; entryId: Uuid }>,
    disposed: boolean,
    disposedOn: CalendarDate | null,
  ): Asset {
    const asset = new Asset(
      id,
      name,
      assetClass,
      assetAccount,
      acquisitionCost,
      acquiredOn,
      route,
      usefulLifeMonths,
      monthlySchedule,
      voucherId,
    );
    for (const booking of depreciations) {
      asset.depreciations.push({
        planMonth: booking.planMonth,
        date: booking.date,
        amount: booking.amount,
        entryId: booking.entryId,
      });
    }
    asset.disposed = disposed;
    asset.disposedOn = disposedOn;
    return asset;
  }

  accumulatedDepreciationAt(asOf: CalendarDate | null): Money {
    let sum = this.acquisitionCost.subtract(this.acquisitionCost); // 0 in Mandantenwährung
    for (const booking of this.depreciations) {
      if (asOf !== null && booking.date.isAfter(asOf)) continue;
      sum = sum.add(booking.amount);
    }
    return sum;
  }

  bookValueAt(asOf: CalendarDate | null): Money {
    if (this.route !== 'capitalize') return this.acquisitionCost.subtract(this.acquisitionCost);
    return this.acquisitionCost.subtract(this.accumulatedDepreciationAt(asOf));
  }

  scheduleSummary(): Record<string, string> {
    if (this.monthlySchedule.length === 0) return {};

    const summary: Record<string, string> = {};
    let total = this.acquisitionCost.subtract(this.acquisitionCost);
    let runStart = 1;

    this.monthlySchedule.forEach((amount, index) => {
      total = total.add(amount);
      const isLast = index === this.monthlySchedule.length - 1;
      const next = isLast ? null : this.monthlySchedule[index + 1]!;
      if (next !== null && next.equals(amount)) return;
      summary[`months${runStart}to${index + 1}`] = amount.amountAsString();
      runStart = index + 2;
    });

    summary.total = total.amountAsString();
    return summary;
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      name: this.name,
      assetClass: this.assetClass,
      assetAccount: this.assetAccount.value,
      route: this.route,
      acquisitionCost: this.acquisitionCost.toJSON(),
      acquiredOn: this.acquiredOn.iso,
      usefulLifeMonths: this.usefulLifeMonths,
      status: this.disposed ? 'disposed' : 'active',
      disposedOn: this.disposedOn?.iso ?? null,
      voucherId: this.voucherId.value,
    };
  }
}
