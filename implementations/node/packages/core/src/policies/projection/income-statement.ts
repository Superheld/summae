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
/** Catch-all key, identical to the one importMapping already assigns (error catalogue). */
const UNASSIGNED = '_unassigned';

/** Neutral, jurisdiction-free — there is no mapping entry to take a label from. */
const UNASSIGNED_LABEL = 'Unassigned';

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
    // Accounts the mapping does not cover. A gap is not an error (error catalogue: gapWarnings[]
    // + catch-all, the same treatment importMapping gives it) — but it must not be silence
    // either: the amount used to be dropped here while the balance sheet, which sums income
    // accounts by type, kept counting it. Two reports, same money, different answers, no hint.
    const gapAccounts = new Set<string>();

    for (const entry of this.journal.forFiscalYear(fiscalYear)) {
      const period = entry.periodRef.period;
      if (period < fromPeriod || period > throughPeriod) continue;

      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null || isBalanceCarrying(account.type)) continue;
        const leaf = mapping.leafFor(account.number.value);
        const key = leaf?.key ?? UNASSIGNED;
        if (leaf === null) gapAccounts.add(account.number.value);
        const signed = line.side === 'credit' ? line.money : line.money.negate();
        amounts.set(key, (amounts.get(key) ?? zero).add(signed));
        touched.add(key);
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

    // The catch-all comes last and only when it carries something — an empty one would put a
    // "nothing is missing" line into every report, which is noise rather than information.
    const unassigned = amounts.get(UNASSIGNED) ?? zero;
    if (touched.has(UNASSIGNED)) {
      netIncome = netIncome.add(unassigned);
      positions.push({ key: UNASSIGNED, label: UNASSIGNED_LABEL, amount: unassigned.amountAsString() });
    }

    // Sorted by account number (code points) so the list is deterministic; it names the accounts
    // that actually contributed, which is what a reader of THIS report needs. Whether a mapping
    // covers every account regardless of postings is importMapping's question, and it answers it.
    const gapWarnings = [...gapAccounts]
      .sort((a, b) => (a < b ? -1 : a > b ? 1 : 0))
      .map((account) => ({ account, assignedTo: UNASSIGNED }));

    return { positions, netIncome: netIncome.amountAsString(), gapWarnings };
  }
}
