import { DomainError } from '../domain-error.js';
import type { MappingRegistry } from '../mapping/mapping-registry.js';
import type { AccountRepository, JournalRepository } from '../port.js';
import type { Currency } from '../shared/currency.js';
import { Money } from '../shared/money.js';
import { isBalanceCarrying } from '../ledger/types.js';

/**
 * GuV als Projektion über ein Mapping (SF-09). Vorzeichen: Haben − Soll
 * (Erträge positiv, Aufwand negativ); netIncome = Summe der Positionen.
 * fromPeriod/throughPeriod grenzen ab (Monats-GuV als BWA-Grundlage).
 */
export class IncomeStatementProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly mappings: MappingRegistry,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = typeof params.fiscalYear === 'number' ? params.fiscalYear : 0;
    const fromPeriod = typeof params.fromPeriod === 'number' ? params.fromPeriod : 1;
    const throughPeriod =
      typeof params.throughPeriod === 'number' ? params.throughPeriod : Number.MAX_SAFE_INTEGER;
    const mappingId = typeof params.mapping === 'string' ? params.mapping : '';

    const mapping = this.mappings.byId(mappingId);
    if (mapping === null) {
      throw new DomainError('E_MAPPING_OVERLAP', `Mapping "${mappingId}" ist nicht geladen`);
    }

    const zero = Money.zero(this.baseCurrency);
    const amounts = new Map<string, Money>();
    const touched = new Set<string>();

    for (const entry of this.journal.forFiscalYear(fiscalYear)) {
      const period = entry.periodRef.period;
      if (period < fromPeriod || period > throughPeriod) continue;

      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null || isBalanceCarrying(account.type)) continue;
        const leaf = mapping.leafFor(account.number.value);
        if (leaf === null) continue;
        const signed = line.side === 'credit' ? line.money : line.money.negate();
        amounts.set(leaf.key, (amounts.get(leaf.key) ?? zero).add(signed));
        touched.add(leaf.key);
      }
    }

    const positions: Array<Record<string, string>> = [];
    let netIncome = zero;

    for (const leaf of mapping.leaves) {
      const amount = amounts.get(leaf.key) ?? zero;
      netIncome = netIncome.add(amount);
      if (amount.isZero() && !touched.has(leaf.key)) continue;
      positions.push({ key: leaf.key, label: leaf.label, amount: amount.amountAsString() });
    }

    return { positions, netIncome: netIncome.amountAsString() };
  }
}
