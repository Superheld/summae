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

export type OpenItemStatus = 'open' | 'partially_settled' | 'settled' | 'cancelled';

/**
 * Why an open item was settled (IMPL-008). `payment` is the ordinary case and the default when the
 * field is absent; `cancellation` arises only from `reverse` and means the item is done because its
 * origin entry was reversed — no money moved. Without the distinction a reversal is indistinguishable
 * from a payment, and cash-basis VAT would declare tax for money that never arrived.
 */
export type SettlementCause = 'payment' | 'cancellation';

export function parseSettlementCause(value: unknown): SettlementCause {
  return value === 'cancellation' ? 'cancellation' : 'payment';
}

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

/**
 * What a business partner is to this tenant. The manual has named these three since the partner
 * record existed; the field was a plain string that took anything, so `custommer` was a partner
 * kind like any other and only turned up as a category nobody could filter on.
 */
export type PartnerKind = 'customer' | 'supplier' | 'both';

export function parsePartnerKind(value: unknown): PartnerKind | null {
  return value === 'customer' || value === 'supplier' || value === 'both' ? value : null;
}
