import { DomainError, rejectedValue } from '../../domain-error.js';
import { FORMAT_VERSION } from '../../substrate/format-version.js';
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
import type { JournalEntry } from '../../substrate/journal-entry.js';
import { integerOrNull } from './parameters.js';

const LINE_FIELDS = ['accountId', 'side', 'money', 'dimensions', 'taxTag'] as const;

function withoutNulls(row: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(row).filter(([, value]) => value !== null));
}

/**
 * GoBD-Z3 export (SF-14): manifest with SHA-256 stream hashes over RFC-8785-
 * canonicalized rows, field catalog, journal complete in sequenceNumber
 * order. auditLog is always part of the export (v0.5/SPEC-005).
 */
/**
 * The only journal format there is. Declared as a set rather than inlined so adding a second one
 * is a data change here, not a new branch: `format` exists to guard the caller's typo, and a
 * parameter that silently accepts anything guards nothing.
 */
const JOURNAL_FORMATS: ReadonlySet<string> = new Set(['gobd-z3']);

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
    // Absent still means gobd-z3; a value that is present and unknown used to be ignored, so the
    // caller got the Z3 stream under whatever label they had typed.
    const format = params.format;
    if (format !== undefined && format !== null && (typeof format !== 'string' || !JOURNAL_FORMATS.has(format))) {
      throw new DomainError('E_INPUT_INVALID', 'journalExport: "format" must be gobd-z3', {
        format: rejectedValue(format),
      });
    }

    const fiscalYear = integerOrNull(params.fiscalYear);
    const entries = fiscalYear === null ? this.journal.all() : this.journal.forFiscalYear(fiscalYear);

    const streams: Record<string, unknown[]> = {
      journal: entries.map((entry) => JournalExportProjection.formatEntry(entry)),
      accounts: this.accounts.all().map((account) => withoutNulls(account.toJSON())),
      vouchers: this.vouchers.all().map((voucher) => withoutNulls(voucher.toJSON())),
    };
    if (this.partners.all().length > 0) {
      // Nulls stripped like the accounts and vouchers above, and for a reason that was latent
      // rather than cosmetic (IMPL-027): the format declares `vatId` as a string and `address` as
      // an object, so a partner without either exported a `null` the schema refuses. Nothing
      // noticed because no schema test had ever exported a partner. One now does.
      streams.partners = this.partners.all().map((partner) => withoutNulls(partner.toJSON()));
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
      fieldCatalog: this.fieldCatalog(Object.keys(streams)),
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

  /**
   * Field catalog (GoBD Z3 description standard): name, type, meaning — **complete** for every
   * stream the export carries.
   *
   * Complete is the whole point and it was not true until 2026-08-28 (IMPL-038). The catalogue
   * named 4 of the account's 8 fields, 2 of the voucher's 12, 4 of the audit record's 9, missed
   * `voucherDate` on the posting, and did not mention the `partners` stream at all — so an auditor
   * reading the description and the data side by side found fields in the data the
   * self-description does not admit to. A Z3 data set that under-describes itself is the one
   * failure this field exists to prevent.
   *
   * Keyed by stream and filtered to the streams actually exported, because `partners` is
   * conditional: describing a stream that is not on the carrier is the same defect mirrored.
   */
  private fieldCatalog(streams: string[]): Record<string, Array<{ name: string; type: string; meaning: string }>> {
    const catalog: Record<string, Array<{ name: string; type: string; meaning: string }>> = {
      journal: [
        { name: 'id', type: 'uuid', meaning: 'Eindeutige Buchungs-ID (UUIDv7)' },
        { name: 'sequenceNumber', type: 'integer', meaning: 'Lückenlose Journalnummer je Geschäftsjahr' },
        { name: 'status', type: 'string', meaning: 'entered|finalized (Festschreibung)' },
        { name: 'entryDate', type: 'date', meaning: 'Buchungsdatum (zonenlos)' },
        { name: 'voucherDate', type: 'date|null', meaning: 'Belegdatum der Buchung, sofern abweichend erfasst' },
        { name: 'recordedAt', type: 'timestamp', meaning: 'Erfassungszeitpunkt' },
        { name: 'periodRef', type: 'object', meaning: 'Geschäftsjahr + Periode' },
        { name: 'voucherId', type: 'uuid', meaning: 'Belegreferenz (Pflicht)' },
        { name: 'text', type: 'string', meaning: 'Buchungstext' },
        { name: 'lines', type: 'array', meaning: 'Positionen: accountId, side (debit|credit), money, dimensions, taxTag' },
        { name: 'reverses', type: 'uuid|null', meaning: 'Rückverweis bei Storno (Generalumkehr)' },
        { name: 'reversedBy', type: 'uuid|null', meaning: 'Verweis auf die Stornobuchung' },
      ],
      accounts: [
        { name: 'id', type: 'uuid', meaning: 'Eindeutige Konto-ID (UUIDv7); die Buchungsposition verweist hierauf, nicht auf die Nummer' },
        { name: 'number', type: 'string', meaning: 'Kontonummer (führende Nullen signifikant)' },
        { name: 'name', type: 'string', meaning: 'Kontobezeichnung' },
        { name: 'type', type: 'string', meaning: 'asset|liability|equity|expense|revenue' },
        { name: 'subtype', type: 'string|null', meaning: 'Kanonischer Subtyp, geschlossenes Repertoire seit Format 0.9 (bank, cash, transit, ar, ap, tax_in, tax_out, result_allocation, fixed_asset, opening_balance, private)' },
        { name: 'status', type: 'string', meaning: 'active|locked — ein gesperrtes Konto nimmt keine Buchung mehr an' },
        { name: 'validFrom', type: 'date|null', meaning: 'Beginn des Gültigkeitsfensters; fehlend = unbegrenzt' },
        { name: 'validTo', type: 'date|null', meaning: 'Ende des Gültigkeitsfensters; fehlend = unbegrenzt' },
      ],
      vouchers: [
        { name: 'id', type: 'uuid', meaning: 'Eindeutige Beleg-ID (UUIDv7); die Buchung verweist hierauf' },
        { name: 'voucherNumber', type: 'string', meaning: 'Belegnummer' },
        { name: 'voucherDate', type: 'date', meaning: 'Belegdatum' },
        { name: 'due', type: 'date|null', meaning: 'Fälligkeit' },
        { name: 'recurring', type: 'boolean|null', meaning: 'Dauerbeleg — wiederholt seine Nummer bestimmungsgemäß' },
        { name: 'economicYear', type: 'integer|null', meaning: 'Wirtschaftsjahr der Zuordnung, wenn vom Buchungsjahr abweichend' },
        { name: 'supplierTaxationMethod', type: 'string|null', meaning: 'Versteuerungsart des Ausstellers (Soll-/Ist-Versteuerung)' },
        { name: 'serviceDate', type: 'date|null', meaning: 'Leistungsdatum (steuerlich entscheidend)' },
        { name: 'servicePeriod', type: 'object|null', meaning: 'Leistungszeitraum {from, to}' },
        { name: 'kind', type: 'string|null', meaning: 'Belegart' },
        { name: 'partnerId', type: 'uuid|null', meaning: 'Verweis auf den Geschäftspartner' },
        { name: 'issuer', type: 'string|null', meaning: 'Aussteller als Freitext, wenn kein Partnersatz existiert' },
      ],
      partners: [
        { name: 'id', type: 'uuid', meaning: 'Eindeutige Partner-ID (UUIDv7)' },
        { name: 'name', type: 'string', meaning: 'Name des Geschäftspartners' },
        { name: 'kind', type: 'string', meaning: 'customer|supplier|both' },
        { name: 'vatId', type: 'string|null', meaning: 'USt-IdNr.' },
        { name: 'paymentTermsDays', type: 'integer|null', meaning: 'Zahlungsziel in Tagen' },
        { name: 'accountNumbers', type: 'array|null', meaning: 'Zugeordnete Personenkonten' },
        { name: 'address', type: 'object|null', meaning: 'Anschrift, vom Kern unverändert gespeichert' },
        { name: 'status', type: 'string', meaning: 'active|inactive (Format 0.7); fehlend gilt als active' },
      ],
      auditLog: [
        { name: 'id', type: 'uuid', meaning: 'Eindeutige Satz-ID (UUIDv7)' },
        { name: 'at', type: 'timestamp', meaning: 'Änderungszeitpunkt' },
        { name: 'actor', type: 'string', meaning: 'Audit-Identität' },
        { name: 'objectType', type: 'string', meaning: 'Art des geänderten Objekts (account, entry, period, …)' },
        { name: 'objectId', type: 'uuid', meaning: 'Identität des geänderten Objekts' },
        { name: 'action', type: 'string', meaning: 'created|corrected|finalized|locked|…' },
        { name: 'changes', type: 'object', meaning: 'Vorher/Nachher-Diff der geänderten Felder' },
        { name: 'previousRecordHash', type: 'string|null', meaning: 'SHA-256 des Vorgängersatzes (Format 0.8); null beim ersten Satz und bei vor 0.8 geschriebenen Sätzen' },
        { name: 'recordHash', type: 'string|null', meaning: 'SHA-256 dieses Satzes ohne dieses Feld selbst (Format 0.8)' },
      ],
    };

    return Object.fromEntries(streams.filter((name) => name in catalog).map((name) => [name, catalog[name]!]));
  }
}
