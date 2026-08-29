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

/**
 * The canonical subtypes an account may carry.
 *
 * **Why this is closed.** `subtype` is the field through which the chart tells the engine what an
 * account *is*: which movements are profit-neutral, which account is a tax account and on which
 * side its tax stands, which posting opens a receivable. The field was a free string, so a pack
 * that wrote `tax-out` instead of `tax_out` produced an account that looked annotated and was
 * inert — the VAT return simply skipped it, and nothing in the output said a tax account had gone
 * missing. That is the same defect the tax mechanisms were closed for in v0.8.0 (`reverse-charge`
 * fell back to plain VAT under the ordinary reporting key) and the one `PartnerKind` was closed
 * for (`custommer` was a partner kind like any other). Third time, same shape, same answer.
 *
 * **The stored value stays a string.** This union is the validator, not a change to the data
 * format — exactly as `PartnerKind` is. Nothing here changes what an export writes.
 *
 * **Where it is enforced, and deliberately where it is not.** At the two boundaries where a
 * subtype is *authored*: `createAccount`/`importChartOfAccounts` (`E_INPUT_INVALID` /
 * `E_COA_FORMAT_INVALID`) and pack resolution (`E_PACK_INCOHERENT`, so a composed pack fails at
 * `resolvePack` rather than at the first posting). It is **not** enforced in the `Account`
 * constructor, and that is not an oversight: hydrating a stored account runs through the same
 * constructor, so a database written before this repertoire existed would stop loading. A
 * validation that refuses to read what it once wrote is a worse failure than the one it prevents.
 *
 * **Two tiers, one list.** Eight of these the engine reads and branches on; `fixed_asset`,
 * `opening_balance` and `private` are annotation that every shipped pack carries and no code
 * consults. They are in the repertoire because the packs use them, and keeping them here is what
 * makes the list safe to check against — a repertoire that only held the eight would refuse the
 * three shipped charts.
 */
export type AccountSubtype =
  /** Read: cash-basis (profit-neutral movement), payment account. */
  | 'bank'
  /** Read: cash journal (F-CORE-030), cash-basis. */
  | 'cash'
  /** Read: cash-basis — money in transit is not a profit event. */
  | 'transit'
  /** Read: a debit opens a receivable. */
  | 'ar'
  /** Read: a credit opens a payable. */
  | 'ap'
  /** Read: VAT return (input side), cash-basis, DATEV export. */
  | 'tax_in'
  /** Read: VAT return (output side), cash-basis, DATEV export. */
  | 'tax_out'
  /** Read: where an appropriated result lands (F-CORE-038). */
  | 'result_allocation'
  /** Read: stock, the only account `valuateInventory` may value onto (F-CORE-050). */
  | 'inventory'
  /** Read: provisions, the only account `recognizeProvision` may form one on (F-CORE-051). */
  | 'provision'
  /** Annotation: the packs mark their asset accounts; the asset expansion uses its own module. */
  | 'fixed_asset'
  /** Annotation: the opening-balance account of a chart. */
  | 'opening_balance'
  /** Annotation: owner's drawings and contributions. */
  | 'private';

/**
 * The repertoire, in declaration order. Published for the same reason `TaxMechanisms.all()` is: a
 * document that names the subtypes — `docs/handbuch` and the pack docs do — is making a checkable
 * claim, and a claim nothing checks goes stale.
 */
export const ACCOUNT_SUBTYPES: readonly AccountSubtype[] = [
  'bank',
  'cash',
  'transit',
  'ar',
  'ap',
  'tax_in',
  'tax_out',
  'result_allocation',
  'inventory',
  'provision',
  'fixed_asset',
  'opening_balance',
  'private',
];

export function isAccountSubtype(value: unknown): value is AccountSubtype {
  return typeof value === 'string' && (ACCOUNT_SUBTYPES as readonly string[]).includes(value);
}
