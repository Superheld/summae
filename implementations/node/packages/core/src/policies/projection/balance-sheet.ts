import { DomainError } from '../../domain-error.js';
import { leafMatches } from './mapping/mapping.js';
import type { MappingRegistry } from './mapping/mapping-registry.js';
import type { AccountRepository, JournalRepository } from '../../port.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';

type Section = 'assets' | 'liabilitiesAndEquity';

/**
 * Bilanz als Projektion (SF-10): kumulativ zum Stichtag. Position mit
 * includesNetIncome enthält die kumulierten Jahresergebnisse + eigenen Saldo.
 * Seite (v0.5/F-007): `side` am Wurzelknoten; assets = Soll−Haben,
 * liabilitiesAndEquity = Haben−Soll. Default: assets.
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

    const mapping = this.mappings.byId(mappingId);
    if (mapping === null) {
      throw new DomainError('E_MAPPING_OVERLAP', `Mapping "${mappingId}" ist nicht geladen`);
    }

    const zero = Money.zero(this.baseCurrency);
    const debits = new Map<string, Money>();
    const credits = new Map<string, Money>();
    const touchedAccounts = new Set<string>();
    let netIncome = zero;

    for (const entry of this.journal.all()) {
      if (asOf !== null && entry.entryDate.isAfter(asOf)) continue;
      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null) continue;

        if (!isBalanceCarrying(account.type)) {
          netIncome = line.side === 'credit' ? netIncome.add(line.money) : netIncome.subtract(line.money);
          continue;
        }

        const key = account.number.value;
        if (line.side === 'debit') debits.set(key, (debits.get(key) ?? zero).add(line.money));
        else credits.set(key, (credits.get(key) ?? zero).add(line.money));
        touchedAccounts.add(key);
      }
    }

    const allNumbers = new Set<string>([...debits.keys(), ...credits.keys()]);
    const sections: Record<Section, Array<Record<string, string>>> = { assets: [], liabilitiesAndEquity: [] };
    const totals: Record<Section, Money> = { assets: zero, liabilitiesAndEquity: zero };

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

    return {
      assets: sections.assets,
      assetsTotal: totals.assets.amountAsString(),
      liabilitiesAndEquity: sections.liabilitiesAndEquity,
      liabilitiesAndEquityTotal: totals.liabilitiesAndEquity.amountAsString(),
    };
  }
}
