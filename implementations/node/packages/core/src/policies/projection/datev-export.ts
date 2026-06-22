import type {
  AccountRepository,
  JournalRepository,
  PartnerRepository,
  VoucherRepository,
} from '../../port.js';
import type { EntryLine } from '../../substrate/entry-line.js';
import type { JournalEntry } from '../../substrate/journal-entry.js';
import type { TaxCodeRegistry } from '../expansion/tax/tax-code-registry.js';

const TAX_SUBTYPES = new Set(['tax_in', 'tax_out']);

function pad2(value: number): string {
  return String(value).padStart(2, '0');
}
function tagCode(line: EntryLine): string | null {
  const code = line.taxTag?.code;
  return typeof code === 'string' ? code : null;
}

/**
 * DATEV-Export (F-IO-005): Buchungsstapel, Kontenbeschriftungen, Partner-
 * Stammdaten. Steuerzeilen werden DATEV-seitig aus dem BU-Schlüssel erzeugt und
 * deshalb in die Basiszeile gefaltet; zusammengesetzte Buchungen in Teilzeilen.
 */
export class DatevExportProjection {
  constructor(
    private readonly journal: JournalRepository,
    private readonly accounts: AccountRepository,
    private readonly vouchers: VoucherRepository,
    private readonly partners: PartnerRepository,
    private readonly registry: TaxCodeRegistry,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const kind = typeof params.kind === 'string' ? params.kind : 'entries';
    const rows =
      kind === 'accounts' ? this.accountRows() : kind === 'partners' ? this.partnerRows() : this.entryRows(params);
    return { kind, rows, rowCount: rows.length };
  }

  private entryRows(params: Record<string, unknown>): Array<Record<string, unknown>> {
    const fiscalYear = typeof params.fiscalYear === 'number' ? params.fiscalYear : null;
    const fromPeriod = typeof params.fromPeriod === 'number' ? params.fromPeriod : 1;
    const throughPeriod = typeof params.throughPeriod === 'number' ? params.throughPeriod : Number.MAX_SAFE_INTEGER;

    const entries = fiscalYear === null ? this.journal.all() : this.journal.forFiscalYear(fiscalYear);
    const rows: Array<Record<string, unknown>> = [];
    for (const entry of entries) {
      const period = entry.periodRef.period;
      if (period < fromPeriod || period > throughPeriod) continue;
      rows.push(...this.splitEntry(entry));
    }
    return rows;
  }

  private splitEntry(entry: JournalEntry): Array<Record<string, unknown>> {
    let lead: EntryLine | null = null;
    const contraLines: EntryLine[] = [];
    const taxLines: EntryLine[] = [];

    for (const line of entry.lines()) {
      const account = this.accounts.byId(line.accountId);
      const isTaxLine =
        account?.subtype != null && TAX_SUBTYPES.has(account.subtype) && line.taxTag !== null;
      if (isTaxLine) {
        taxLines.push(line);
        continue;
      }
      if (lead === null && line.taxTag === null) {
        lead = line;
        continue;
      }
      contraLines.push(line);
    }

    if (lead === null || contraLines.length === 0) return [];

    const voucher = this.vouchers.byId(entry.voucherId);
    const rows: Array<Record<string, unknown>> = [];

    for (const contra of contraLines) {
      let gross = contra.money;
      let buKey: string | null = null;
      const contraCode = tagCode(contra);
      if (contraCode !== null) {
        buKey = this.registry.datevBuFor(contraCode);
        for (const taxLine of taxLines) {
          if (tagCode(taxLine) === contraCode) gross = gross.add(taxLine.money);
        }
      }

      rows.push({
        amount: gross.abs().amountAsString(),
        debitCredit: lead.side === 'debit' ? 'S' : 'H',
        account: lead.account.value,
        contraAccount: contra.account.value,
        buKey,
        documentField1: voucher === null ? '' : voucher.voucherNumber,
        date: `${pad2(entry.entryDate.month())}${pad2(Number(entry.entryDate.iso.slice(8, 10)))}`,
        text: entry.text(),
        finalized: entry.isFinalized(),
      });
    }
    return rows;
  }

  private accountRows(): Array<Record<string, unknown>> {
    return this.accounts.all().map((account) => ({
      number: account.number.value,
      name: account.name,
      type: account.type,
    }));
  }

  private partnerRows(): Array<Record<string, unknown>> {
    return this.partners.all().map((partner) => partner.toJSON());
  }
}
