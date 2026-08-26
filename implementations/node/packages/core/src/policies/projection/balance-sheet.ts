import { DomainError } from '../../domain-error.js';
import { UNASSIGNED, UNASSIGNED_LABEL } from './mapping/unassigned.js';
import { leafMatches } from './mapping/mapping.js';
import type { MappingRegistry } from './mapping/mapping-registry.js';
import type { AccountRepository, JournalRepository } from '../../port.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';
import { integerOrNull } from './parameters.js';

type Section = 'assets' | 'liabilitiesAndEquity';

/**
 * Balance sheet as a projection (SF-10): cumulative as of the reporting date. A position with
 * includesNetIncome contains the cumulative net income + its own balance.
 * Side (v0.5/SPEC-007): `side` at the root node; assets = debit−credit,
 * liabilitiesAndEquity = credit−debit. Default: assets.
 */
export class BalanceSheetProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly mappings: MappingRegistry,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const asOf = typeof params.asOf === 'string' ? CalendarDate.of(params.asOf) : null;
    const mappingId = typeof params.mapping === 'string' ? params.mapping : '';
    // `fiscalYear` used to be read by nobody here: the handbook, the cheat sheet and the
    // gated scenarios all passed it, and the projection silently reported the whole journal
    // instead — two different years returned byte-identical balance sheets.
    //
    // It scopes CUMULATIVELY (everything up to and including that year), i.e. "as at the end
    // of fiscal year N", not "movements of year N". A balance sheet is a snapshot and must
    // balance; applying trialBalance's G1 rule here (income accounts restart each year) tears
    // a hole exactly the size of the prior year's result, because summae deliberately writes
    // no closing entries (`closeFiscalYear` is a pure status change), so that result was never
    // carried into equity. Cumulative keeps assets == liabilities+equity in every year.
    const fiscalYear = integerOrNull(params.fiscalYear);

    const mapping = this.mappings.byId(mappingId);
    if (mapping === null) {
      throw this.mappingRefusal('balanceSheet', mappingId);
    }

    const zero = Money.zero(this.baseCurrency);
    const debits = new Map<string, Money>();
    const credits = new Map<string, Money>();
    const touchedAccounts = new Set<string>();
    // Which side an account belongs on when no position claims it. Taken from the account TYPE,
    // which is jurisdiction-free and always present — the mapping cannot answer it, since the
    // whole problem is that the mapping says nothing about this account.
    const sectionOf = new Map<string, Section>();
    let netIncome = zero;

    for (const entry of this.journal.all()) {
      if (asOf !== null && entry.entryDate.isAfter(asOf)) continue;
      const entryYear = entry.periodRef.fiscalYear;
      if (fiscalYear !== null && entryYear > fiscalYear) continue;

      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null) continue;

        if (!isBalanceCarrying(account.type)) {
          netIncome = line.side === 'credit' ? netIncome.add(line.money) : netIncome.subtract(line.money);
          continue;
        }

        const key = account.number.value;
        sectionOf.set(key, account.type === 'asset' ? 'assets' : 'liabilitiesAndEquity');
        if (line.side === 'debit') debits.set(key, (debits.get(key) ?? zero).add(line.money));
        else credits.set(key, (credits.get(key) ?? zero).add(line.money));
        touchedAccounts.add(key);
      }
    }

    const allNumbers = new Set<string>([...debits.keys(), ...credits.keys()]);
    const sections: Record<Section, Array<Record<string, string>>> = { assets: [], liabilitiesAndEquity: [] };
    const totals: Record<Section, Money> = { assets: zero, liabilitiesAndEquity: zero };

    // An account no position matches used to be visited by nobody: the loop below runs over the
    // POSITIONS and pulls what each one matches, so an unmatched account landed in neither total
    // and the sheet stopped balancing without saying so.
    const unmatched = [...allNumbers].filter((number) => !mapping.leaves.some((leaf) => leafMatches(leaf, number)));

    for (const leaf of mapping.leaves) {
      const section: Section = leaf.side === 'liabilitiesAndEquity' ? 'liabilitiesAndEquity' : 'assets';
      let amount = zero;
      let touched = false;

      for (const number of allNumbers) {
        if (!leafMatches(leaf, number)) continue;
        const debit = debits.get(number) ?? zero;
        const credit = credits.get(number) ?? zero;
        amount =
          section === 'assets'
            ? amount.add(debit).subtract(credit)
            : amount.add(credit).subtract(debit);
        touched = touched || touchedAccounts.has(number);
      }

      if (leaf.includesNetIncome) {
        amount = amount.add(netIncome);
        touched = touched || !netIncome.isZero();
      }

      if (amount.isZero() && !touched) continue;

      sections[section].push({ key: leaf.key, label: leaf.label, amount: amount.amountAsString() });
      totals[section] = totals[section].add(amount);
    }

    // The catch-all per section, appended last and only when it carries something. Amounts follow
    // the same sign rule as the section they land in, so the identity holds again.
    for (const section of ['assets', 'liabilitiesAndEquity'] as const) {
      let amount = zero;
      let touched = false;

      for (const number of unmatched) {
        if ((sectionOf.get(number) ?? 'assets') !== section) continue;
        const debit = debits.get(number) ?? zero;
        const credit = credits.get(number) ?? zero;
        amount = section === 'assets' ? amount.add(debit).subtract(credit) : amount.add(credit).subtract(debit);
        touched = touched || touchedAccounts.has(number);
      }

      if (!touched) continue;
      sections[section].push({ key: UNASSIGNED, label: UNASSIGNED_LABEL, amount: amount.amountAsString() });
      totals[section] = totals[section].add(amount);
    }

    const gapWarnings = unmatched
      .sort((a, b) => (a < b ? -1 : a > b ? 1 : 0))
      .map((account) => ({ account, assignedTo: UNASSIGNED }));

    return {
      assets: sections.assets,
      assetsTotal: totals.assets.amountAsString(),
      liabilitiesAndEquity: sections.liabilitiesAndEquity,
      liabilitiesAndEquityTotal: totals.liabilitiesAndEquity.amountAsString(),
      gapWarnings,
    };
  }

  /**
   * Which mappings this tenant could use — part of the refusal, because the refusal is the only
   * place a caller learns it. A `default` tenant has none at all: the neutral pack ships no mapping
   * module, since a jurisdiction-free chart has no lawful statement layout to ship (IMPL-032). That
   * is a legitimate answer and used to arrive as "requires the parameter mapping", which reads as
   * *you forgot something* rather than *this pack cannot do this*.
   */
  private mappingRefusal(projection: string, mappingId: string): DomainError {
    const available = this.mappings.summaries().map((summary) => summary.id);
    if (available.length === 0) {
      return new DomainError(
        'E_INPUT_INVALID',
        `${projection} needs a mapping and this tenant has none: its pack ships no mapping module, so one has to be loaded with importMapping`,
        { mapping: mappingId, available },
      );
    }
    return new DomainError(
      'E_INPUT_INVALID',
      mappingId === ''
        ? `${projection} requires the parameter "mapping"`
        : `mapping "${mappingId}" is not loaded`,
      { mapping: mappingId, available },
    );
  }
}
