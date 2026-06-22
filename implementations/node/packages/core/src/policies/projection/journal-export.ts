import { createHash } from 'node:crypto';
import { canonicalJson } from '../../substrate/canonical-json.js';
import type { Clock } from '../../substrate/clock.js';
import type { Currency } from '../../substrate/currency.js';
import type { Uuid } from '../../substrate/uuid.js';
import type {
  AccountRepository,
  AuditTrail,
  JournalRepository,
  PartnerRepository,
  VoucherRepository,
} from '../../port.js';
import type { JournalEntry } from '../../ledger/journal-entry.js';

const FORMAT_VERSION = '0.4';
const LINE_FIELDS = ['accountId', 'side', 'money', 'dimensions', 'taxTag'] as const;

function withoutNulls(row: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(row).filter(([, value]) => value !== null));
}

/**
 * GoBD-Z3-Export (SF-14): Manifest mit SHA-256-Strom-Hashes über RFC-8785-
 * kanonisierte Zeilen, Feldkatalog, Journal vollständig in sequenceNumber-
 * Reihenfolge. auditLog ist immer Teil des Exports (v0.5/F-005).
 */
export class JournalExportProjection {
  constructor(
    private readonly tenantId: Uuid,
    private readonly tenantName: string,
    private readonly baseCurrency: Currency,
    private readonly journal: JournalRepository,
    private readonly accounts: AccountRepository,
    private readonly vouchers: VoucherRepository,
    private readonly partners: PartnerRepository,
    private readonly audit: AuditTrail,
    private readonly clock: Clock,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = typeof params.fiscalYear === 'number' ? params.fiscalYear : null;
    const entries = fiscalYear === null ? this.journal.all() : this.journal.forFiscalYear(fiscalYear);

    const streams: Record<string, unknown[]> = {
      journal: entries.map((entry) => JournalExportProjection.formatEntry(entry)),
      accounts: this.accounts.all().map((account) => withoutNulls(account.toJSON())),
      vouchers: this.vouchers.all().map((voucher) => withoutNulls(voucher.toJSON())),
    };
    if (this.partners.all().length > 0) {
      streams.partners = this.partners.all().map((partner) => partner.toJSON());
    }
    streams.auditLog = this.audit.all().map((record) => record.toJSON());

    const contentHashes: Record<string, string> = {};
    for (const [name, rows] of Object.entries(streams)) {
      const lines = rows.map((row) => canonicalJson(row));
      contentHashes[name] = createHash('sha256').update(lines.join('\n')).digest('hex');
    }

    const allFinalized = entries.every((entry) => entry.isFinalized());

    return {
      manifest: {
        formatVersion: FORMAT_VERSION,
        tenantId: this.tenantId.value,
        tenantName: this.tenantName,
        baseCurrency: this.baseCurrency.code,
        exportedAt: this.clock.now().toISOString(),
        hashAlgorithm: 'sha256',
        streams: Object.keys(streams),
        contentHashes,
      },
      fieldCatalogIncluded: true,
      fieldCatalog: this.fieldCatalog(),
      journal: { entryCount: entries.length, ordering: 'sequenceNumber', allFinalized },
      data: streams,
    };
  }

  private static formatEntry(entry: JournalEntry): Record<string, unknown> {
    const data = entry.toJSON();
    const lines = Array.isArray(data.lines) ? data.lines : [];
    data.lines = lines.map((line) => {
      const source = line as Record<string, unknown>;
      const stripped: Record<string, unknown> = {};
      for (const field of LINE_FIELDS) stripped[field] = source[field];
      return stripped;
    });
    return data;
  }

  private fieldCatalog(): Record<string, Array<{ name: string; type: string; meaning: string }>> {
    return {
      journal: [
        { name: 'id', type: 'uuid', meaning: 'Eindeutige Buchungs-ID (UUIDv7)' },
        { name: 'sequenceNumber', type: 'integer', meaning: 'Lückenlose Journalnummer je Geschäftsjahr' },
        { name: 'status', type: 'string', meaning: 'entered|finalized (Festschreibung)' },
        { name: 'entryDate', type: 'date', meaning: 'Buchungsdatum (zonenlos)' },
        { name: 'recordedAt', type: 'timestamp', meaning: 'Erfassungszeitpunkt' },
        { name: 'periodRef', type: 'object', meaning: 'Geschäftsjahr + Periode' },
        { name: 'voucherId', type: 'uuid', meaning: 'Belegreferenz (Pflicht)' },
        { name: 'text', type: 'string', meaning: 'Buchungstext' },
        { name: 'lines', type: 'array', meaning: 'Positionen: Konto, Seite, Betrag, Dimensionen, Steuer-Tag' },
        { name: 'reverses', type: 'uuid|null', meaning: 'Rückverweis bei Storno (Generalumkehr)' },
        { name: 'reversedBy', type: 'uuid|null', meaning: 'Verweis auf die Stornobuchung' },
      ],
      accounts: [
        { name: 'number', type: 'string', meaning: 'Kontonummer (führende Nullen signifikant)' },
        { name: 'name', type: 'string', meaning: 'Kontobezeichnung' },
        { name: 'type', type: 'string', meaning: 'asset|liability|equity|expense|revenue' },
        { name: 'subtype', type: 'string|null', meaning: 'Kanonischer Subtyp (bank, ar, ap, …)' },
      ],
      vouchers: [
        { name: 'voucherNumber', type: 'string', meaning: 'Belegnummer' },
        { name: 'voucherDate', type: 'date', meaning: 'Belegdatum' },
      ],
      auditLog: [
        { name: 'at', type: 'timestamp', meaning: 'Änderungszeitpunkt' },
        { name: 'actor', type: 'string', meaning: 'Audit-Identität' },
        { name: 'action', type: 'string', meaning: 'created|corrected|finalized|locked|…' },
        { name: 'changes', type: 'object', meaning: 'Vorher/Nachher-Diff der geänderten Felder' },
      ],
    };
  }
}
