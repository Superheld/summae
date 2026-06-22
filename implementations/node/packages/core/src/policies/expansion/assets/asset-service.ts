import { DomainError } from '../../../domain-error.js';
import type { Ledger } from '../../../ledger/ledger.js';
import { Voucher } from '../../../ledger/voucher.js';
import type { AssetRepository, FiscalYearRepository, VoucherRepository } from '../../../port.js';
import { AccountNumber } from '../../../substrate/account-number.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import type { IdGenerator } from '../../../substrate/id-generator.js';
import { Money } from '../../../substrate/money.js';
import { Uuid } from '../../../substrate/uuid.js';
import { Asset } from './asset.js';
import { type AssetRoute, parseAssetRoute } from './asset-route.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

interface Threshold {
  validFrom: string;
  validTo: string | null;
  immediateMax: string;
  poolMin: string | null;
  poolMax: string | null;
}

/**
 * Anlagen-Nebenbuch (assets-modell.md): GWG-Weiche beim Zugang, AfA-Lauf
 * idempotent je Lauf-Ziel, Buchungen über den Ledger (sofort festgeschrieben).
 * AfA-Verteilung: Monatswerte = allocate der AHK über die Laufzeit (flach).
 */
export class AssetService {
  private ruleModule: Record<string, unknown> = {};

  constructor(
    private readonly baseCurrency: Currency,
    private readonly assets: AssetRepository,
    private readonly fiscalYears: FiscalYearRepository,
    private readonly vouchers: VoucherRepository,
    private readonly ledger: Ledger,
    private readonly ids: IdGenerator,
  ) {}

  setRuleModule(ruleModule: Record<string, unknown>): void {
    this.ruleModule = ruleModule;
  }

  acquire(input: Record<string, unknown>): Record<string, unknown> {
    const name = asString(input.name) ?? '';
    const assetClass = asString(input.assetClass) ?? '';
    const assetAccount = AccountNumber.of(asString(input.assetAccount) ?? '0');
    const cost = this.parseMoney(input.acquisitionCost);
    const acquiredOn = CalendarDate.of(asString(input.acquiredOn) ?? '');
    const voucherIdRaw = asString(input.voucherId);
    if (voucherIdRaw === null) throw new InvalidValue('acquireAsset braucht voucherId');
    const voucherId = Uuid.fromString(voucherIdRaw);
    const choice = asString(input.gwgChoice) ?? 'auto';

    const route = this.resolveRoute(choice, cost, acquiredOn);

    let usefulLifeMonths: number | null = null;
    const schedule: Money[] = [];
    if (route === 'capitalize') {
      usefulLifeMonths = this.usefulLifeMonths(assetClass);
      schedule.push(...cost.allocateEvenly(usefulLifeMonths));
    } else if (route === 'pool') {
      // Sammelposten § 6 Abs. 2a: starr 5 Jahre je 1/5.
      usefulLifeMonths = 60;
      for (const yearAmount of cost.allocateEvenly(5)) {
        schedule.push(...yearAmount.allocateEvenly(12));
      }
    }

    const asset = new Asset(
      this.ids.next(),
      name,
      assetClass,
      assetAccount,
      cost,
      acquiredOn,
      route,
      usefulLifeMonths,
      schedule,
      voucherId,
    );
    this.assets.add(asset);

    const targetAccount = route === 'immediate_expense' ? this.gwgExpenseAccount() : assetAccount.value;
    this.postMachineEntry(acquiredOn, voucherId, `Anlagenzugang ${name}`, [
      { account: targetAccount, side: 'debit', money: cost.toJSON() },
      { account: this.counterAccount(), side: 'credit', money: cost.toJSON() },
    ]);

    const result = asset.toJSON();
    result.route = route;
    if (route === 'immediate_expense') result.expenseAccount = targetAccount;
    return result;
  }

  dispose(input: Record<string, unknown>): Record<string, unknown> {
    const asset = this.requireAsset(input.assetId);
    asset.assertActive();

    const disposedOn = CalendarDate.of(asString(input.disposedOn) ?? '');
    asset.dispose(disposedOn);
    this.assets.save(asset);

    const proceeds = isRecord(input.proceeds) ? this.parseMoney(input.proceeds) : null;
    const proceedsAccount = asString(input.proceedsAccount);
    const bankAccount = asString(input.bankAccount) ?? this.counterAccount();

    if (proceeds !== null && proceedsAccount !== null) {
      const voucherId = asString(input.voucherId) ? Uuid.fromString(asString(input.voucherId)!) : asset.voucherId;
      this.postMachineEntry(disposedOn, voucherId, `Anlagenabgang ${asset.name}`, [
        { account: bankAccount, side: 'debit', money: proceeds.toJSON() },
        { account: proceedsAccount, side: 'credit', money: proceeds.toJSON() },
      ]);
    }

    return asset.toJSON();
  }

  runDepreciation(input: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = typeof input.fiscalYear === 'number' ? input.fiscalYear : 0;
    const period = typeof input.period === 'number' ? input.period : null;

    let entriesCreated = 0;
    let total = Money.zero(this.baseCurrency);

    for (const asset of this.assets.all()) {
      if (asset.route !== 'capitalize' && asset.route !== 'pool') continue;
      if (asset.isDisposed()) continue;

      const [months, amount] =
        period === null ? this.yearTarget(asset, fiscalYear) : this.monthTarget(asset, fiscalYear, period);
      if (months.length === 0 || amount.isZero()) continue;

      const bookingDate = this.bookingDate(asset, fiscalYear, period, months);
      const periodLabel = period === null ? '' : `/${String(period).padStart(2, '0')}`;
      const entry = this.postMachineEntry(
        bookingDate,
        this.depreciationVoucher(asset, fiscalYear, period),
        `AfA ${asset.name} ${fiscalYear}${periodLabel}`,
        [
          { account: this.depreciationExpenseAccount(), side: 'debit', money: amount.toJSON() },
          { account: asset.assetAccount.value, side: 'credit', money: amount.toJSON() },
        ],
      );

      const monthAmounts = months.length === 1 ? [amount] : this.monthAmounts(asset, months, amount);
      months.forEach((planMonth, index) => {
        asset.recordDepreciation(planMonth, bookingDate, monthAmounts[index]!, entry);
      });

      this.assets.save(asset);
      entriesCreated++;
      total = total.add(amount);
    }

    if (entriesCreated === 0) return { alreadyRun: true, entriesCreated: 0 };
    return { entriesCreated, totalDepreciation: total.toJSON() };
  }

  requireAsset(assetId: unknown): Asset {
    let asset: Asset | null = null;
    if (typeof assetId === 'string' && assetId !== '') {
      try {
        asset = this.assets.byId(Uuid.fromString(assetId));
      } catch (error) {
        if (!(error instanceof InvalidValue)) throw error;
      }
    }
    if (asset === null) {
      throw new DomainError('E_ASSET_UNKNOWN', `Anlagegut ${typeof assetId === 'string' ? assetId : '?'} existiert nicht`);
    }
    return asset;
  }

  // ---- intern ----------------------------------------------------------

  private yearTarget(asset: Asset, fiscalYear: number): [number[], Money] {
    const zero = Money.zero(this.baseCurrency);
    const monthsByYear = new Map<number, number[]>();
    const life = asset.monthlySchedule.length;
    for (let planMonth = 1; planMonth <= life; planMonth++) {
      const year = asset.planMonthDate(planMonth).year();
      const list = monthsByYear.get(year) ?? [];
      list.push(planMonth);
      monthsByYear.set(year, list);
    }
    const months = monthsByYear.get(fiscalYear);
    if (months === undefined) return [[], zero];

    const years = [...monthsByYear.keys()];
    const weights = years.map((year) => monthsByYear.get(year)!.length);
    const yearAmounts = asset.acquisitionCost.allocate(...weights);
    const yearIndex = years.indexOf(fiscalYear);
    if (yearIndex === -1) return [[], zero];
    const yearAmount = yearAmounts[yearIndex]!;

    const openMonths: number[] = [];
    let bookedAmount = zero;
    for (const planMonth of months) {
      if (asset.isMonthBooked(planMonth)) {
        bookedAmount = bookedAmount.add(asset.monthlySchedule[planMonth - 1]!);
        continue;
      }
      openMonths.push(planMonth);
    }

    const amount = yearAmount.subtract(bookedAmount);
    if (openMonths.length === 0 || !amount.isPositive()) return [[], zero];
    return [openMonths, amount];
  }

  private monthTarget(asset: Asset, fiscalYear: number, period: number): [number[], Money] {
    const zero = Money.zero(this.baseCurrency);
    const year = this.fiscalYears.byYear(fiscalYear);
    if (year === null) {
      throw new DomainError('E_PERIOD_UNKNOWN', `Geschäftsjahr ${fiscalYear} ist nicht angelegt`);
    }
    const periodEntity = year.period(period);
    const life = asset.monthlySchedule.length;
    for (let planMonth = 1; planMonth <= life; planMonth++) {
      const date = asset.planMonthDate(planMonth);
      if (!periodEntity.contains(date)) continue;
      if (asset.isMonthBooked(planMonth)) return [[], zero];
      return [[planMonth], asset.monthlySchedule[planMonth - 1]!];
    }
    return [[], zero];
  }

  private monthAmounts(asset: Asset, months: number[], total: Money): Money[] {
    const planned = months.map((planMonth) => asset.monthlySchedule[planMonth - 1]!);
    let plannedSum = Money.zero(this.baseCurrency);
    for (const amount of planned) plannedSum = plannedSum.add(amount);
    if (plannedSum.equals(total)) return planned;
    return total.allocateEvenly(months.length);
  }

  private bookingDate(asset: Asset, fiscalYear: number, period: number | null, months: number[]): CalendarDate {
    const year = this.fiscalYears.byYear(fiscalYear);
    if (period !== null && year !== null) return year.period(period).end;
    if (year !== null) return year.end;
    return asset.planMonthDate(months[months.length - 1]!);
  }

  private postMachineEntry(
    date: CalendarDate,
    voucherId: Uuid,
    text: string,
    lines: Array<Record<string, unknown>>,
  ): Uuid {
    const result = this.ledger.post({ entryDate: date.iso, voucherId: voucherId.value, text, lines });
    // Maschinell erzeugte Buchung: sofort festschreiben (GoBD).
    this.ledger.finalize({ entryId: result.entry.id.value });
    return result.entry.id;
  }

  private depreciationVoucher(asset: Asset, fiscalYear: number, period: number | null): Uuid {
    const periodLabel = period === null ? '' : `-${String(period).padStart(2, '0')}`;
    const voucher = new Voucher({
      id: this.ids.next(),
      voucherNumber: `AFA-${fiscalYear}${periodLabel}-${asset.id.value.slice(-6)}`,
      voucherDate: CalendarDate.of(`${String(fiscalYear).padStart(4, '0')}-12-31`),
      kind: 'internal',
    });
    this.vouchers.add(voucher);
    return voucher.id;
  }

  private resolveRoute(choice: string, cost: Money, acquiredOn: CalendarDate): AssetRoute {
    if (choice !== 'auto') return parseAssetRoute(choice) ?? 'capitalize';

    for (const threshold of this.thresholds()) {
      const validFrom = CalendarDate.of(threshold.validFrom);
      const validTo = threshold.validTo === null ? null : CalendarDate.of(threshold.validTo);
      if (acquiredOn.isBefore(validFrom) || (validTo !== null && acquiredOn.isAfter(validTo))) continue;

      if (cost.compareTo(Money.of(threshold.immediateMax, this.baseCurrency)) <= 0) return 'immediate_expense';
      if (
        threshold.poolMin !== null &&
        threshold.poolMax !== null &&
        cost.compareTo(Money.of(threshold.poolMin, this.baseCurrency)) >= 0 &&
        cost.compareTo(Money.of(threshold.poolMax, this.baseCurrency)) <= 0
      ) {
        return 'pool';
      }
    }
    return 'capitalize';
  }

  private thresholds(): Threshold[] {
    const raw = Array.isArray(this.ruleModule.gwgThresholds) ? this.ruleModule.gwgThresholds : [];
    const thresholds: Threshold[] = [];
    for (const item of raw) {
      if (!isRecord(item) || typeof item.validFrom !== 'string' || typeof item.immediateMax !== 'string') continue;
      thresholds.push({
        validFrom: item.validFrom,
        validTo: typeof item.validTo === 'string' ? item.validTo : null,
        immediateMax: item.immediateMax,
        poolMin: typeof item.poolMin === 'string' ? item.poolMin : null,
        poolMax: typeof item.poolMax === 'string' ? item.poolMax : null,
      });
    }
    return thresholds;
  }

  private usefulLifeMonths(assetClass: string): number {
    const raw = Array.isArray(this.ruleModule.usefulLife) ? this.ruleModule.usefulLife : [];
    for (const item of raw) {
      if (isRecord(item) && item.assetClass === assetClass && typeof item.months === 'number') {
        return item.months;
      }
    }
    throw new DomainError(
      'E_ASSET_UNKNOWN',
      `Keine Nutzungsdauer für Anlagenklasse "${assetClass}" im Regelmodul (siehe SPEC-FINDINGS)`,
    );
  }

  private counterAccount(): string {
    return this.assetAccount('acquisitionCounterAccount');
  }
  private depreciationExpenseAccount(): string {
    return this.assetAccount('depreciationExpenseAccount');
  }
  private gwgExpenseAccount(): string {
    return this.assetAccount('gwgExpenseAccount');
  }

  private assetAccount(key: string): string {
    const block = isRecord(this.ruleModule.assetAccounts) ? this.ruleModule.assetAccounts : {};
    const value = block[key];
    if (typeof value === 'string' && value !== '') return value;
    throw new DomainError('E_ACCOUNT_UNKNOWN', `assetAccounts.${key} ist im Regelmodul nicht gesetzt`, { key });
  }

  private parseMoney(raw: unknown): Money {
    const amount = isRecord(raw) ? asString(raw.amount) : null;
    if (amount === null) throw new InvalidValue('Betrag fehlt');
    return Money.of(amount, this.baseCurrency);
  }
}
