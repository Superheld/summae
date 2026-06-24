/** Debit/credit. */
export type Side = 'debit' | 'credit';

/**
 * Account type determines the balance mechanics (ledger-modell.md): balance-sheet accounts
 * accumulate over years, income accounts per fiscal year.
 */
export type AccountType = 'asset' | 'liability' | 'equity' | 'expense' | 'revenue';

const BALANCE_CARRYING: ReadonlySet<AccountType> = new Set<AccountType>([
  'asset',
  'liability',
  'equity',
]);

/** Balance-sheet account: balance carries forward implicitly (no closing/opening account). */
export function isBalanceCarrying(type: AccountType): boolean {
  return BALANCE_CARRYING.has(type);
}

export function isAccountType(value: unknown): value is AccountType {
  return (
    value === 'asset' ||
    value === 'liability' ||
    value === 'equity' ||
    value === 'expense' ||
    value === 'revenue'
  );
}

export type AccountStatus = 'active' | 'locked';

/** Record lifecycle: entered (correctable) → finalized (only reversal). */
export type EntryStatus = 'entered' | 'finalized';

export type PeriodStatus = 'open' | 'closed';

export type FiscalYearStatus = 'open' | 'closed';

export type OpenItemKind = 'receivable' | 'payable';

export type OpenItemStatus = 'open' | 'partially_settled' | 'settled';

/**
 * Settlement with difference (api.md G2): cash discount, bad debt, minor difference.
 * The difference must be materialized as explicit posting line(s).
 */
export type SettlementDifferenceKind = 'discount' | 'bad_debt' | 'minor';

export function parseSettlementDifferenceKind(value: unknown): SettlementDifferenceKind | null {
  return value === 'discount' || value === 'bad_debt' || value === 'minor' ? value : null;
}

export function parseOpenItemKind(value: unknown): OpenItemKind | null {
  return value === 'receivable' || value === 'payable' ? value : null;
}
