import { DomainError, rejectedValue } from '../../../domain-error.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { Ledger } from '../../../ledger/ledger.js';
import { Voucher } from '../../../records/voucher.js';
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
  poolYears: number | null;
  poolReducedOnDisposal: boolean | null;
  poolProRataInFirstYear: boolean | null;
}

/**
 * Asset subledger (assets-modell.md): low-value-asset switch at acquisition,
 * depreciation run idempotent per run target, postings through the ledger (finalized immediately).
 * Depreciation distribution: monthly values = allocate of the acquisition cost over the useful life (flat).
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
    // An asset run posts through the ledger, so `journalEntry/created` records exist — but
    // nothing in them says *an asset was acquired*. These records carry the asset event
    // itself (F-CORE-014); the depreciation run has no object of its own, so it names the
    // tenant, like the configuration singletons.
    private readonly tenantId: Uuid | null = null,
    private readonly audit: AuditWriter | null = null,
  ) {}

  private trace(
    input: Record<string, unknown>,
    objectType: string,
    objectId: Uuid,
    action: string,
    changes: Record<string, { from: unknown; to: unknown }>,
  ): void {
    if (this.audit === null) return;
    this.audit.record(this.audit.actorOf(input), objectType, objectId, action, changes);
  }

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
    if (voucherIdRaw === null) throw new InvalidValue('acquireAsset requires voucherId');
    const voucherId = Uuid.fromString(voucherIdRaw);
    const choice = asString(input.gwgChoice) ?? 'auto';
    const dimensions = AssetService.parseDimensions(input.dimensions);

    const route = this.resolveRoute(choice, cost, acquiredOn);
    const explicitLife = AssetService.parseUsefulLifeMonths(input.usefulLifeMonths);

    // Refused, not ignored. A pooled asset takes its term from the pack's poolYears and an
    // immediately expensed one has no schedule at all, so a life given with either route cannot be
    // honoured — and dropping it in silence would let a caller believe a number took effect that
    // never did.
    if (explicitLife !== null && route !== 'capitalize') {
      throw new DomainError(
        'E_INPUT_INVALID',
        `acquireAsset: "usefulLifeMonths" applies to a capitalised asset, not to route "${route}"`,
        { usefulLifeMonths: explicitLife, route },
      );
    }

    let usefulLifeMonths: number | null = null;
    const schedule: Money[] = [];
    if (route === 'capitalize') {
      // The caller's own figure wins over the class average. A table of class averages cannot serve a
      // jurisdiction that lets a taxpayer prove a shorter life for an individual asset, however
      // complete the table is — and without this parameter an asset class missing from the pack was
      // simply unusable.
      usefulLifeMonths = explicitLife ?? this.usefulLifeMonths(assetClass);
      schedule.push(...cost.allocateEvenly(usefulLifeMonths));
    } else if (route === 'pool') {
      // Pool period comes from the pack (SPEC-004): a fixed five years used to sit here, which is one
      // jurisdiction's rule, so every other jurisdiction with a pooled de-minimis regime would have
      // inherited it silently. The pack says over how long; the core only spreads it evenly.
      const poolYears = this.poolYears(acquiredOn);
      usefulLifeMonths = poolYears * 12;
      for (const yearAmount of cost.allocateEvenly(poolYears)) {
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
      dimensions,
      this.planStartFor(route, acquiredOn),
    );
    this.assets.add(asset);

    const targetAccount = route === 'immediate_expense' ? this.gwgExpenseAccount() : assetAccount.value;
    this.postMachineEntry(acquiredOn, voucherId, `Asset acquisition ${name}`, this.withDimensions(asset, [
      { account: targetAccount, side: 'debit', money: cost.toJSON() },
      { account: this.counterAccount(), side: 'credit', money: cost.toJSON() },
    ]));

    this.trace(input, 'asset', asset.id, 'acquired', {
      name: { from: null, to: name },
      assetClass: { from: null, to: assetClass },
      acquiredOn: { from: null, to: acquiredOn.iso },
      route: { from: null, to: route },
    });

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
    const bankAccount = asString(input.bankAccount) ?? this.counterAccount();
    const voucherId = asString(input.voucherId) ? Uuid.fromString(asString(input.voucherId)!) : asset.voucherId;

    // Where the pack keeps a disposed item in the pool (F-AST-006, see runDepreciation), the pool
    // keeps running its term and there is no carrying amount of its own to clear — only the
    // proceeds are booked. Where the pack takes it out, it is written off like any other asset.
    const staysPooled = this.staysInPool(asset);
    if (!staysPooled) this.catchUpDepreciation(asset, disposedOn, voucherId);
    const carrying = staysPooled ? Money.zero(this.baseCurrency) : asset.bookValueAt(disposedOn);
    const lines = this.disposalLines(asset, carrying, proceeds, bankAccount, asString(input.proceedsAccount));

    if (lines.length > 0) {
      this.postMachineEntry(disposedOn, voucherId, `Asset disposal ${asset.name}`, this.withDimensions(asset, lines));
    }

    this.trace(input, 'asset', asset.id, 'disposed', {
      status: { from: 'active', to: 'disposed' },
      disposedOn: { from: null, to: disposedOn.iso },
    });

    return asset.toJSON();
  }

  runDepreciation(input: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = typeof input.fiscalYear === 'number' ? input.fiscalYear : 0;
    const period = typeof input.period === 'number' ? input.period : null;

    let entriesCreated = 0;
    let total = Money.zero(this.baseCurrency);

    for (const asset of this.assets.all()) {
      if (asset.route !== 'capitalize' && asset.route !== 'pool') continue;
      // A disposed asset stops depreciating — unless its pack keeps it in the pool. Whether a
      // disposal reduces the pool is declared per jurisdiction (`poolReducedOnDisposal`); where it
      // does not, the pool runs its fixed term no matter what happened to the individual items
      // (F-AST-006). Stopping unconditionally understated depreciation and overstated profit for
      // every remaining year of the term.
      if (asset.isDisposed() && !this.staysInPool(asset)) continue;

      const [months, amount] =
        period === null ? this.yearTarget(asset, fiscalYear) : this.monthTarget(asset, fiscalYear, period);
      if (months.length === 0 || amount.isZero()) continue;

      const bookingDate = this.bookingDate(asset, fiscalYear, period, months);
      const periodLabel = period === null ? '' : `/${String(period).padStart(2, '0')}`;
      const entry = this.postMachineEntry(
        bookingDate,
        this.depreciationVoucher(asset, fiscalYear, period),
        `Depreciation ${asset.name} ${fiscalYear}${periodLabel}`,
        this.withDimensions(asset, [
          { account: this.depreciationExpenseAccount(), side: 'debit', money: amount.toJSON() },
          { account: asset.assetAccount.value, side: 'credit', money: amount.toJSON() },
        ]),
      );

      const monthAmounts = months.length === 1 ? [amount] : this.monthAmounts(asset, months, amount);
      months.forEach((planMonth, index) => {
        asset.recordDepreciation(planMonth, bookingDate, monthAmounts[index]!, entry);
      });

      this.assets.save(asset);
      entriesCreated++;
      total = total.add(amount);
    }

    // A run that created nothing is still an event: "someone ran depreciation for this
    // period and it was already done" is exactly what an auditor reconstructing a timeline
    // wants to see, and leaving it out would make repeated runs invisible.
    if (this.tenantId !== null) {
      this.trace(input, 'depreciationRun', this.tenantId, 'completed', {
        fiscalYear: { from: null, to: fiscalYear },
        period: { from: null, to: period },
        entriesCreated: { from: null, to: entriesCreated },
      });
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
      throw new DomainError('E_ASSET_UNKNOWN', `asset ${typeof assetId === 'string' ? assetId : '?'} does not exist`);
    }
    return asset;
  }

  // ---- internal --------------------------------------------------------

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
      throw new DomainError('E_PERIOD_UNKNOWN', `fiscal year ${fiscalYear} is not set up`);
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

  /**
   * Dimensions the asset carries, in the shape a posting line expects (IMPL-023). Every machine
   * entry about an asset gets them on every line: the whole event belongs to that cost centre, and
   * a line without them would be refused wherever the pack makes a dimension mandatory — which is
   * precisely the case that used to make depreciation impossible to run.
   */
  private static parseDimensions(raw: unknown): Array<{ type: string; code: string }> {
    if (!Array.isArray(raw)) return [];
    const parsed: Array<{ type: string; code: string }> = [];
    for (const item of raw) {
      if (!isRecord(item)) continue;
      const type = asString(item.type);
      const code = asString(item.code);
      if (type !== null && code !== null) parsed.push({ type, code });
    }
    return parsed;
  }

  private withDimensions(
    asset: Asset,
    lines: Array<Record<string, unknown>>,
  ): Array<Record<string, unknown>> {
    if (asset.dimensions.length === 0) return lines;
    return lines.map((line) => ({ ...line, dimensions: asset.dimensions.map((d) => ({ ...d })) }));
  }

  private postMachineEntry(
    date: CalendarDate,
    voucherId: Uuid,
    text: string,
    lines: Array<Record<string, unknown>>,
  ): Uuid {
    const result = this.ledger.post({ entryDate: date.iso, voucherId: voucherId.value, text, lines });
    // Machine-generated entry: finalize immediately (machine entries are not hand-correctable).
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

    const threshold = this.applicableThreshold(acquiredOn);
    if (threshold === null) return 'capitalize';

    if (cost.compareTo(Money.of(threshold.immediateMax, this.baseCurrency)) <= 0) return 'immediate_expense';
    if (
      threshold.poolMin !== null &&
      threshold.poolMax !== null &&
      cost.compareTo(Money.of(threshold.poolMin, this.baseCurrency)) >= 0 &&
      cost.compareTo(Money.of(threshold.poolMax, this.baseCurrency)) <= 0
    ) {
      return 'pool';
    }
    return 'capitalize';
  }

  /** The threshold row in force on the acquisition date — the first whose validity window contains it. */
  private applicableThreshold(acquiredOn: CalendarDate): Threshold | null {
    for (const threshold of this.thresholds()) {
      const validFrom = CalendarDate.of(threshold.validFrom);
      const validTo = threshold.validTo === null ? null : CalendarDate.of(threshold.validTo);
      if (acquiredOn.isBefore(validFrom) || (validTo !== null && acquiredOn.isAfter(validTo))) continue;
      return threshold;
    }
    return null;
  }

  /**
   * How long a pooled asset is written off. Refused rather than defaulted: a pack that opens a pool
   * range without saying over how long is incomplete, and picking a number here would put a statute
   * back into the core — the exact thing SPEC-004 is about. The schema requires the field alongside
   * `poolMax`, so this fires only for hand-fed rule data that never went through a pack.
   */
  private poolYears(acquiredOn: CalendarDate): number {
    const threshold = this.applicableThreshold(acquiredOn);
    if (threshold === null || threshold.poolYears === null) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        'gwgThresholds: a pool range (poolMin/poolMax) without poolYears — the pack must say over how many years the pool is written off',
        { field: 'poolYears', acquiredOn: acquiredOn.iso },
      );
    }
    return threshold.poolYears;
  }

  /**
   * Whether a disposal takes the item out of the pool. Same reasoning as `poolYears`, and the same
   * refusal: this is a jurisdiction's answer, not a property of pooling. Germany does not reduce
   * the pool when an item leaves (the yearly fraction runs to the end of the term regardless);
   * the UK and Australia take disposals out of their pools. Deciding it here would have put a
   * statute back into the core — which is exactly what IMPL-019 accidentally did before this.
   */
  /**
   * Does the pool's first year get shortened by the acquisition month?
   *
   * Germany says no: the pool is dissolved in the fiscal year it is formed and the following ones by
   * equal fractions, so an asset bought in November still carries a full fraction in that year, and
   * the term ends after `poolYears` fiscal years. Treating a pool like ordinary linear depreciation —
   * pro rata from the month of acquisition — understated the first year and invented a last one.
   *
   * Other pool regimes answer this differently, which is why the pack has to say it rather than the
   * core assuming it — the same reason `poolYears` (SPEC-004) and `poolReducedOnDisposal` (IMPL-019)
   * are pack data.
   */
  private poolProRataInFirstYear(acquiredOn: CalendarDate): boolean {
    const threshold = this.applicableThreshold(acquiredOn);
    if (threshold === null || threshold.poolProRataInFirstYear === null) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        'gwgThresholds: a pool range (poolMin/poolMax) without poolProRataInFirstYear — the pack must say whether the first year is shortened by the acquisition month',
        { field: 'poolProRataInFirstYear', acquiredOn: acquiredOn.iso },
      );
    }
    return threshold.poolProRataInFirstYear;
  }

  /**
   * Where the depreciation plan starts. Capitalised assets start in the month of acquisition (pro rata
   * temporis). A pooled asset whose pack dissolves the pool in whole fiscal-year fractions starts at
   * the beginning of the fiscal year it was acquired in — that, and nothing else, is what makes the
   * first year full and the term end after `poolYears` years.
   */
  private planStartFor(route: AssetRoute, acquiredOn: CalendarDate): CalendarDate | null {
    if (route !== 'pool') return null;

    const year = this.fiscalYears.forDate(acquiredOn);

    // Acquired on the first day of the fiscal year? Then both answers produce the same plan, and the
    // pack is not asked. That is deliberate and mirrors `poolReducedOnDisposal`, which is only demanded
    // when something is actually disposed: a pack owes an answer where the answer changes the books,
    // not everywhere. The guarantee that a *pack* carries the field is the schema's job.
    if (year !== null && acquiredOn.iso === year.start.iso) return null;

    if (this.poolProRataInFirstYear(acquiredOn)) return null;

    if (year === null) {
      throw new DomainError(
        'E_PERIOD_UNKNOWN',
        `the pool for an asset acquired on ${acquiredOn.iso} is dissolved in whole fiscal-year fractions, but no fiscal year contains that date`,
        { acquiredOn: acquiredOn.iso },
      );
    }

    return year.start;
  }

  private poolReducedOnDisposal(acquiredOn: CalendarDate): boolean {
    const threshold = this.applicableThreshold(acquiredOn);
    if (threshold === null || threshold.poolReducedOnDisposal === null) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        'gwgThresholds: a pool range (poolMin/poolMax) without poolReducedOnDisposal — the pack must say whether a disposal reduces the pool',
        { field: 'poolReducedOnDisposal', acquiredOn: acquiredOn.iso },
      );
    }
    return threshold.poolReducedOnDisposal;
  }

  /** A pooled asset that its pack keeps in the pool after disposal — the write-off does not apply. */
  private staysInPool(asset: Asset): boolean {
    return asset.route === 'pool' && !this.poolReducedOnDisposal(asset.acquiredOn);
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
        poolYears: typeof item.poolYears === 'number' && Number.isSafeInteger(item.poolYears) && item.poolYears >= 1
          ? item.poolYears
          : null,
        poolReducedOnDisposal: typeof item.poolReducedOnDisposal === 'boolean' ? item.poolReducedOnDisposal : null,
        poolProRataInFirstYear:
          typeof item.poolProRataInFirstYear === 'boolean' ? item.poolProRataInFirstYear : null,
      });
    }
    return thresholds;
  }

  /**
   * A useful life given per acquisition: a whole number of months, at least one. JSON has no
   * int/float split, so 60.0 is the same value as 60 — but 60.4 is a caller's mistake and not a
   * number to round into shape.
   */
  private static parseUsefulLifeMonths(value: unknown): number | null {
    if (value === null || value === undefined) return null;

    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 1) {
      throw new DomainError(
        'E_INPUT_INVALID',
        'acquireAsset: "usefulLifeMonths" must be a whole number of months, at least 1',
        { usefulLifeMonths: rejectedValue(value) },
      );
    }

    return value;
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
      `No useful life for asset class "${assetClass}" in the rule module (see SPEC-FINDINGS)`,
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
  /**
   * Depreciation owed up to the disposal, booked before the write-off (IMPL-022).
   *
   * Without this the disposal wrote off whatever carrying amount happened to be booked, and the
   * asset's last months of depreciation never happened at all: `runDepreciation` skips disposed
   * assets, so nobody would book them afterwards either. The expense landed in the disposal
   * account as an inflated loss instead of in depreciation — the total hit the income statement
   * correctly, the split did not, and the depreciation figure the fixed-asset schedule reports
   * was short.
   *
   * Which months are owed follows the schedule's own due-date convention — a plan month falls due
   * on its last day, exactly as `monthTarget` reads it for the regular run. No new rule is
   * invented here, and deliberately so: whether the month an asset leaves in counts as a whole
   * month is a *jurisdiction's* answer (Germany grants it, US conventions are half-year or
   * mid-quarter), so it belongs in a pack, not in this code. Consequence today: an asset disposed
   * mid-month gets no depreciation for that month. Recorded as a follow-up.
   */
  private catchUpDepreciation(asset: Asset, disposedOn: CalendarDate, voucherId: Uuid): void {
    const due: number[] = [];
    for (let planMonth = 1; planMonth <= asset.monthlySchedule.length; planMonth++) {
      if (asset.planMonthDate(planMonth).isAfter(disposedOn)) break;
      if (!asset.isMonthBooked(planMonth)) due.push(planMonth);
    }
    if (due.length === 0) return;

    let amount = Money.zero(this.baseCurrency);
    for (const planMonth of due) amount = amount.add(asset.monthlySchedule[planMonth - 1]!);
    if (amount.isZero()) return;

    const entry = this.postMachineEntry(
      disposedOn,
      voucherId,
      `Depreciation up to disposal ${asset.name}`,
      this.withDimensions(asset, [
        { account: this.depreciationExpenseAccount(), side: 'debit', money: amount.toJSON() },
        { account: asset.assetAccount.value, side: 'credit', money: amount.toJSON() },
      ]),
    );

    const amounts = this.monthAmounts(asset, due, amount);
    due.forEach((planMonth, index) => {
      asset.recordDepreciation(planMonth, disposedOn, amounts[index]!, entry);
    });
    this.assets.save(asset);
  }

  private disposalProceedsAccount(): string {
    return this.assetAccount('disposalProceedsAccount');
  }
  private disposalLossAccount(): string {
    return this.assetAccount('disposalLossAccount');
  }

  /**
   * The disposal entry (F-AST-004). Two things leave the books at once: the asset's carrying
   * amount, and the difference between that and what the sale brought in.
   *
   * This core depreciates *net* — `runDepreciation` credits the asset account directly, there is
   * no accumulated-depreciation account — so the write-off is a single credit of the carrying
   * amount against that same account, not the gross form with an offsetting contra account.
   *
   * The difference is a gain (proceeds above book value) or a loss (below, including a scrapping
   * with no proceeds at all), and it goes to the account the pack names for it. Before this,
   * `dispose` booked only `bank → proceedsAccount`: the asset stayed in the balance sheet at its
   * carrying amount and the proceeds counted as income in full, overstating profit by exactly
   * that amount.
   *
   * The `proceedsAccount` input parameter still wins over the pack's account — it is documented
   * and fixtures pass it — but is no longer required for the entry to happen.
   */
  private disposalLines(
    asset: Asset,
    carrying: Money,
    proceeds: Money | null,
    bankAccount: string,
    proceedsAccountOverride: string | null,
  ): Array<Record<string, unknown>> {
    const lines: Array<Record<string, unknown>> = [];
    const received = proceeds ?? Money.zero(this.baseCurrency);

    if (received.isPositive()) {
      lines.push({ account: bankAccount, side: 'debit', money: received.toJSON() });
    }
    if (carrying.isPositive()) {
      lines.push({ account: asset.assetAccount.value, side: 'credit', money: carrying.toJSON() });
    }

    const difference = received.subtract(carrying);
    if (difference.isPositive()) {
      const gainAccount = proceedsAccountOverride ?? this.disposalProceedsAccount();
      lines.push({ account: gainAccount, side: 'credit', money: difference.toJSON() });
    } else if (!difference.isZero()) {
      lines.push({ account: this.disposalLossAccount(), side: 'debit', money: difference.negate().toJSON() });
    }

    // Nothing moved: a fully depreciated asset scrapped without proceeds. Booking a zero entry
    // would put an empty voucher in the journal for no reason.
    return lines.length > 1 ? lines : [];
  }

  private assetAccount(key: string): string {
    const block = isRecord(this.ruleModule.assetAccounts) ? this.ruleModule.assetAccounts : {};
    const value = block[key];
    if (typeof value === 'string' && value !== '') return value;
    throw new DomainError('E_ACCOUNT_UNKNOWN', `assetAccounts.${key} is not set in the rule module`, { key });
  }

  private parseMoney(raw: unknown): Money {
    const amount = isRecord(raw) ? asString(raw.amount) : null;
    if (amount === null) throw new InvalidValue('amount missing');
    return Money.of(amount, this.baseCurrency);
  }
}
