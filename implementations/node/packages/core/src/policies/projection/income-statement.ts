import { DomainError } from '../../domain-error.js';
import type { MappingRegistry } from './mapping/mapping-registry.js';
import type { AccountRepository, JournalRepository } from '../../port.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';
import { integerOr } from './parameters.js';

/**
 * Income statement as a projection over a mapping (SF-09). Sign: credit − debit
 * (revenue positive, expense negative); netIncome = sum of the positions.
 * fromPeriod/throughPeriod restrict the range (monthly income statement as BWA basis).
 */
export class IncomeStatementProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly mappings: MappingRegistry,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = integerOr(params.fiscalYear, 0);
    const fromPeriod = integerOr(params.fromPeriod, 1);
    const throughPeriod = integerOr(params.throughPeriod, Number.MAX_SAFE_INTEGER);
    const mappingId = typeof params.mapping === 'string' ? params.mapping : '';

    // A missing or unknown mapping is a caller mistake, not an overlap: reporting it as
    // E_MAPPING_OVERLAP (the code for two positions claiming the same account) sent operators
    // hunting the wrong thing, and an omitted parameter produced `Mapping "" is not loaded`.
    const mapping = this.mappings.byId(mappingId);
    if (mapping === null) {
      throw new DomainError(
        'E_INPUT_INVALID',
        mappingId === '' ? 'incomeStatement requires the parameter "mapping"' : `mapping "${mappingId}" is not loaded`,
        { mapping: mappingId },
      );
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
