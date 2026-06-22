/** Soll/Haben. */
export type Side = 'debit' | 'credit';

/**
 * Kontotyp bestimmt die Saldenmechanik (ledger-modell.md): Bestandskonten
 * kumulieren über Jahre, Erfolgskonten je Geschäftsjahr.
 */
export type AccountType = 'asset' | 'liability' | 'equity' | 'expense' | 'revenue';

const BALANCE_CARRYING: ReadonlySet<AccountType> = new Set<AccountType>([
  'asset',
  'liability',
  'equity',
]);

/** Bestandskonto: Saldo trägt implizit vor (kein SBK/EBK). */
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

/** GoBD-Lebenszyklus: erfasst (korrigierbar) → festgeschrieben (nur Storno). */
export type EntryStatus = 'entered' | 'finalized';

export type PeriodStatus = 'open' | 'closed';

export type FiscalYearStatus = 'open' | 'closed';

export type OpenItemKind = 'receivable' | 'payable';

export type OpenItemStatus = 'open' | 'partially_settled' | 'settled';

/**
 * Ausgleich mit Differenz (api.md G2): Skonto, Forderungsausfall, Kleindifferenz.
 * Die Differenz muss als Buchungszeile(n) sichtbar sein (§ 17 UStG).
 */
export type SettlementDifferenceKind = 'discount' | 'bad_debt' | 'minor';

export function parseSettlementDifferenceKind(value: unknown): SettlementDifferenceKind | null {
  return value === 'discount' || value === 'bad_debt' || value === 'minor' ? value : null;
}

export function parseOpenItemKind(value: unknown): OpenItemKind | null {
  return value === 'receivable' || value === 'payable' ? value : null;
}
