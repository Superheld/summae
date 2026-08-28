import { DomainError, rejectedValue } from '../../domain-error.js';
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
import { integerOrNull } from './parameters.js';

/**
 * The Z3 data carrier: flat files plus the `index.xml` that describes them (F-IO-012).
 *
 * **What this closes.** `journalExport` has always produced the *self-describing data set* — streams
 * plus a field catalogue — and `datenformat.md` stated the intent that "export in the
 * Beschreibungsstandard is a **mapping**, not an invention". The mapping itself was not in the
 * package, so an audit asking for a Z3 data carrier needed tooling summae did not ship, and
 * `docs/gobd-conformance.md` §10 carried that as its last open row. It is a mapping and nothing more:
 * every value here already exists in the books.
 *
 * **Why this is a projection in the core and not pack data.** It looks jurisdiction-specific, and it
 * is — but so are `datevExport` (German) and `auditDataExport` (the US AICPA standard), and both live
 * here. The rule the three follow: a **published exchange format** is core code selected by the
 * caller; what varies *inside* it by jurisdiction is pack data. This one takes no pack data at all —
 * it describes summae's own streams — so there is nothing for a pack to supply.
 *
 * **Standard: version 1.6 of 1 March 2019**, DTD `gdpdu-01-03-2019.dtd`. The structure follows the
 * DTD exactly, and the element order is not decoration: `Table` fixes `URL, Name?, Description?,
 * Validity?, codepage?, (DecimalSymbol, DigitGroupingSymbol)?, …, (VariableLength | FixedLength)`,
 * and an importer rejects the file if they are shuffled.
 *
 * **Three decisions worth naming.**
 *
 * 1. **The journal is flattened to one row per line**, with the entry's header repeated. A CSV cannot
 *    nest, and an auditor's first act is to sum debit and credit per account — which needs the line,
 *    not the entry. `entryId` + `lineNumber` is the primary key, and both keys are declared, so the
 *    importer can join rather than being handed five unrelated files.
 * 2. **Nothing is written to disk**, because summae is a library and owns no file system. The tables
 *    come back as content, and the embedding writes them next to `index.xml`.
 * 3. **The DTD file itself is named, not shipped.** The standard requires it on the medium next to
 *    `index.xml`; it is Audicon's document, not ours, and a library that quietly redistributed a
 *    third party's normative file would be making a promise about its version that it cannot keep.
 *    `notProvided` says so rather than leaving it to be discovered at the audit desk.
 */
const GDPDU_STANDARD = 'Beschreibungsstandard für die Datenträgerüberlassung 1.6 (2019-03-01)';
const GDPDU_DTD = 'gdpdu-01-03-2019.dtd';

/** `;` and `"` are the standard's own defaults; declared explicitly rather than relied on. */
const COLUMN_DELIMITER = ';';
const TEXT_ENCAPSULATOR = '"';
const RECORD_DELIMITER = '\r\n';

type ColumnType =
  | { kind: 'alphanumeric' }
  | { kind: 'date' }
  | { kind: 'numeric'; accuracy: number };

interface Column {
  readonly name: string;
  readonly description: string;
  readonly type: ColumnType;
  readonly primaryKey?: boolean;
}

interface TableSpec {
  readonly url: string;
  readonly name: string;
  readonly description: string;
  readonly columns: readonly Column[];
  readonly rows: ReadonlyArray<ReadonlyArray<string>>;
  readonly foreignKeys?: ReadonlyArray<{ columns: readonly string[]; references: string }>;
}

const ALPHA: ColumnType = { kind: 'alphanumeric' };
const DATE: ColumnType = { kind: 'date' };

function text(value: unknown): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  return JSON.stringify(value);
}

/**
 * CSV with the standard's quoting: a value is wrapped when it carries the delimiter, a quote or a
 * line break, and an inner quote is doubled. Unconditional quoting would also be legal and is not
 * used, because a file an auditor may open by hand should be readable by hand.
 */
function csvValue(value: string): string {
  const needsQuoting =
    value.includes(COLUMN_DELIMITER) || value.includes(TEXT_ENCAPSULATOR) || /[\r\n]/.test(value);
  if (!needsQuoting) return value;
  return TEXT_ENCAPSULATOR + value.split(TEXT_ENCAPSULATOR).join(TEXT_ENCAPSULATOR + TEXT_ENCAPSULATOR) + TEXT_ENCAPSULATOR;
}

function csv(columns: readonly Column[], rows: ReadonlyArray<ReadonlyArray<string>>): string {
  const header = columns.map((column) => csvValue(column.name)).join(COLUMN_DELIMITER);
  const body = rows.map((row) => row.map(csvValue).join(COLUMN_DELIMITER));
  // A trailing record delimiter, so the last row ends like every other one. An importer that counts
  // records by delimiter and one that counts by line then agree.
  return [header, ...body].join(RECORD_DELIMITER) + RECORD_DELIMITER;
}

function xmlText(value: string): string {
  return value
    .split('&').join('&amp;')
    .split('<').join('&lt;')
    .split('>').join('&gt;');
}

export class GdpduExportProjection {
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
    const mediaName = params.mediaName;
    if (mediaName !== undefined && mediaName !== null && typeof mediaName !== 'string') {
      throw new DomainError('E_INPUT_INVALID', 'gdpduExport: "mediaName" must be a string', {
        mediaName: rejectedValue(mediaName),
      });
    }

    const fiscalYear = integerOrNull(params.fiscalYear);
    const tables = this.tables(fiscalYear);

    return {
      standard: GDPDU_STANDARD,
      dtd: GDPDU_DTD,
      indexXml: this.indexXml(String(mediaName ?? 'Disk1'), tables),
      tables: tables.map((table) => ({
        url: table.url,
        name: table.name,
        rowCount: table.rows.length,
        content: csv(table.columns, table.rows),
      })),
      // The same shape `systemDescription` uses, and for the same reason: what a deliverable does
      // NOT contain is part of the deliverable.
      notProvided: [
        `${GDPDU_DTD} itself — the standard requires it on the medium beside index.xml; obtain it from the`
        + ' publisher of the Beschreibungsstandard and place it there. summae names the version it wrote'
        + ' against rather than redistributing a normative document it does not own.',
        'Writing the files. summae is a library and owns no file system: index.xml and every table come'
        + ' back as content for the embedding to write.',
        'Document images. The carrier holds the bookkeeping data and the voucher REFERENCE; the voucher'
        + ' files themselves are the archive\'s, as everywhere else in summae.',
      ],
    };
  }

  private tables(fiscalYear: number | null): TableSpec[] {
    const entries = fiscalYear === null ? this.journal.all() : this.journal.forFiscalYear(fiscalYear);
    const accountNames = new Map<string, string>();
    for (const account of this.accounts.all()) {
      const data = account.toJSON();
      accountNames.set(text(data.number), text(data.name));
    }
    const voucherNumbers = new Map<string, string>();
    for (const voucher of this.vouchers.all()) {
      const data = voucher.toJSON();
      voucherNumbers.set(text(data.id), text(data.voucherNumber));
    }

    const scale = this.baseCurrency.scale;
    const journalRows: string[][] = [];
    for (const entry of entries) {
      const data = entry.toJSON();
      const lines = Array.isArray(data.lines) ? data.lines : [];
      lines.forEach((raw, index) => {
        const line = raw as Record<string, unknown>;
        const money = (line.money ?? {}) as Record<string, unknown>;
        const tag = (line.taxTag ?? null) as Record<string, unknown> | null;
        const account = text(line.account);
        journalRows.push([
          text(data.id),
          String(index + 1),
          text(data.sequenceNumber),
          text(data.entryDate),
          text(data.voucherId),
          voucherNumbers.get(text(data.voucherId)) ?? '',
          text(data.text),
          account,
          accountNames.get(account) ?? '',
          text(line.side),
          text(money.amount),
          text(money.currency),
          tag === null ? '' : text(tag.code),
          tag === null ? '' : text(tag.reportingKey),
          text(data.status),
          text(data.reverses),
          text(data.reversedBy),
          text(data.recordedAt),
        ]);
      });
    }

    const tables: TableSpec[] = [
      {
        url: 'journal.csv',
        name: 'Journal',
        description: 'Buchungssätze, eine Zeile je Buchungsposition (Journalfunktion)',
        columns: [
          { name: 'entryId', description: 'Eindeutige Buchungs-ID (UUIDv7)', type: ALPHA, primaryKey: true },
          { name: 'lineNumber', description: 'Position innerhalb der Buchung, ab 1', type: { kind: 'numeric', accuracy: 0 }, primaryKey: true },
          { name: 'sequenceNumber', description: 'Lückenlose Journalnummer je Geschäftsjahr', type: { kind: 'numeric', accuracy: 0 } },
          { name: 'entryDate', description: 'Buchungsdatum (zonenlos)', type: DATE },
          { name: 'voucherId', description: 'Belegreferenz (Pflicht)', type: ALPHA },
          { name: 'voucherNumber', description: 'Belegnummer des referenzierten Belegs', type: ALPHA },
          { name: 'text', description: 'Buchungstext', type: ALPHA },
          { name: 'accountNumber', description: 'Kontonummer (führende Nullen signifikant)', type: ALPHA },
          { name: 'accountName', description: 'Kontobezeichnung zum Zeitpunkt des Exports', type: ALPHA },
          { name: 'side', description: 'debit|credit (Soll/Haben)', type: ALPHA },
          { name: 'amount', description: 'Betrag in Belegwährung, Vorzeichen positiv', type: { kind: 'numeric', accuracy: scale } },
          { name: 'currency', description: 'ISO-4217-Code', type: ALPHA },
          { name: 'taxCode', description: 'Steuerschlüssel des Packs, leer wenn ungetaggt', type: ALPHA },
          { name: 'taxReportingKey', description: 'Meldeschlüssel (z. B. UStVA-Kennzahl)', type: ALPHA },
          { name: 'status', description: 'entered|finalized (Festschreibung)', type: ALPHA },
          { name: 'reverses', description: 'Bei Storno: ID der stornierten Buchung', type: ALPHA },
          { name: 'reversedBy', description: 'Bei stornierter Buchung: ID der Stornobuchung', type: ALPHA },
          { name: 'recordedAt', description: 'Erfassungszeitpunkt (kanonisch, UTC)', type: ALPHA },
        ],
        rows: journalRows,
        foreignKeys: [
          { columns: ['accountNumber'], references: 'accounts.csv' },
          { columns: ['voucherId'], references: 'vouchers.csv' },
        ],
      },
      {
        url: 'accounts.csv',
        name: 'Konten',
        description: 'Kontenplan (Kontenfunktion)',
        columns: [
          { name: 'number', description: 'Kontonummer', type: ALPHA, primaryKey: true },
          { name: 'name', description: 'Kontobezeichnung', type: ALPHA },
          { name: 'type', description: 'asset|liability|equity|expense|revenue', type: ALPHA },
          { name: 'subtype', description: 'Kanonischer Subtyp (bank, cash, ar, ap, tax_in, …)', type: ALPHA },
          { name: 'status', description: 'active|locked', type: ALPHA },
        ],
        rows: this.accounts.all().map((account) => {
          const data = account.toJSON();
          return [text(data.number), text(data.name), text(data.type), text(data.subtype), text(data.status)];
        }),
      },
      {
        url: 'vouchers.csv',
        name: 'Belege',
        description: 'Belegstammdaten; die Belegbilder selbst gehören dem Archiv',
        columns: [
          { name: 'voucherId', description: 'Eindeutige Beleg-ID', type: ALPHA, primaryKey: true },
          { name: 'voucherNumber', description: 'Belegnummer', type: ALPHA },
          { name: 'voucherDate', description: 'Belegdatum', type: DATE },
          { name: 'serviceDate', description: 'Leistungsdatum, falls erfasst — maßgeblich für die Steuerregelversion', type: DATE },
          { name: 'kind', description: 'Belegart', type: ALPHA },
          { name: 'partnerId', description: 'Geschäftspartner, falls zugeordnet', type: ALPHA },
        ],
        rows: this.vouchers.all().map((voucher) => {
          const data = voucher.toJSON();
          return [
            text(data.id),
            text(data.voucherNumber),
            text(data.voucherDate),
            text(data.serviceDate),
            text(data.kind),
            text(data.partnerId),
          ];
        }),
      },
    ];

    const partners = this.partners.all();
    if (partners.length > 0) {
      tables.push({
        url: 'partners.csv',
        name: 'Geschaeftspartner',
        description: 'Debitoren/Kreditoren-Stammdaten',
        columns: [
          { name: 'partnerId', description: 'Eindeutige Partner-ID', type: ALPHA, primaryKey: true },
          { name: 'name', description: 'Name des Geschäftspartners', type: ALPHA },
          { name: 'kind', description: 'customer|supplier|both', type: ALPHA },
          { name: 'vatId', description: 'USt-IdNr., falls erfasst', type: ALPHA },
        ],
        rows: partners.map((partner) => {
          const data = partner.toJSON();
          return [text(data.id), text(data.name), text(data.kind), text(data.vatId)];
        }),
      });
    }

    tables.push({
      url: 'auditLog.csv',
      name: 'Aenderungsprotokoll',
      description: 'Audit-Trail; seit Format 0.8 hash-verkettet (Unveränderbarkeit prüfbar)',
      columns: [
        { name: 'recordId', description: 'Eindeutige Satz-ID', type: ALPHA, primaryKey: true },
        { name: 'at', description: 'Änderungszeitpunkt (kanonisch, UTC)', type: ALPHA },
        { name: 'actor', description: 'Vom Aufrufer gemeldete Identität; nie verifiziert', type: ALPHA },
        { name: 'objectType', description: 'Betroffener Objekttyp; "redacted" = nach Löschrecht geleerte Hülle', type: ALPHA },
        { name: 'objectId', description: 'Betroffenes Objekt', type: ALPHA },
        { name: 'action', description: 'created|corrected|finalized|locked|…', type: ALPHA },
        { name: 'changes', description: 'Vorher/Nachher-Diff der geänderten Felder (JSON)', type: ALPHA },
        { name: 'previousRecordHash', description: 'SHA-256 des Vorgängersatzes (Hash-Kette)', type: ALPHA },
        { name: 'recordHash', description: 'SHA-256 dieses Satzes', type: ALPHA },
      ],
      rows: this.audit.all().map((record) => {
        const data = record.toJSON();
        return [
          text(data.id),
          text(data.at),
          text(data.actor),
          text(data.objectType),
          text(data.objectId),
          text(data.action),
          JSON.stringify(data.changes ?? {}),
          text(data.previousRecordHash),
          text(data.recordHash),
        ];
      }),
    });

    return tables;
  }

  /**
   * The `index.xml`, in the DTD's element order.
   *
   * Written by hand rather than through an XML library, deliberately and for the same reason the
   * core carries no framework: the file is small, its shape is fixed by a DTD, and the two languages
   * have to emit the **same bytes** — which a serialiser's own whitespace and attribute habits would
   * quietly prevent.
   */
  private indexXml(mediaName: string, tables: readonly TableSpec[]): string {
    const out: string[] = [];
    out.push('<?xml version="1.0" encoding="utf-8" standalone="no"?>');
    out.push(`<!DOCTYPE DataSet SYSTEM "${GDPDU_DTD}">`);
    out.push('<DataSet>');
    out.push('  <Version>1.0</Version>');
    out.push('  <DataSupplier>');
    out.push(`    <Name>${xmlText(this.tenantName)}</Name>`);
    out.push(`    <Location>${xmlText(this.tenantId.value)}</Location>`);
    out.push(`    <Comment>summae, exportiert ${xmlText(this.clock.now().toISOString())}</Comment>`);
    out.push('  </DataSupplier>');
    out.push('  <Media>');
    out.push(`    <Name>${xmlText(mediaName)}</Name>`);
    for (const table of tables) out.push(...this.tableXml(table));
    out.push('  </Media>');
    out.push('</DataSet>');
    return out.join('\n') + '\n';
  }

  private tableXml(table: TableSpec): string[] {
    const out: string[] = [];
    out.push('    <Table>');
    out.push(`      <URL>${xmlText(table.url)}</URL>`);
    out.push(`      <Name>${xmlText(table.name)}</Name>`);
    out.push(`      <Description>${xmlText(table.description)}</Description>`);
    out.push('      <UTF8 />');
    // Amounts are written the way summae stores them — a dot decimal and no grouping — so the
    // symbols are declared rather than left to the standard's German defaults, which would read
    // "1234.56" as one million.
    out.push('      <DecimalSymbol>.</DecimalSymbol>');
    out.push('      <DigitGroupingSymbol>,</DigitGroupingSymbol>');
    out.push('      <VariableLength>');
    out.push(`        <ColumnDelimiter>${xmlText(COLUMN_DELIMITER)}</ColumnDelimiter>`);
    out.push('        <RecordDelimiter>&#13;&#10;</RecordDelimiter>');
    out.push(`        <TextEncapsulator>${xmlText(TEXT_ENCAPSULATOR)}</TextEncapsulator>`);
    // The DTD requires every primary key BEFORE the ordinary columns, whatever their order in the
    // file: `((VariablePrimaryKey+, VariableColumn*) | (VariableColumn+))`.
    for (const column of table.columns.filter((c) => c.primaryKey === true)) {
      out.push(...this.columnXml('VariablePrimaryKey', column));
    }
    for (const column of table.columns.filter((c) => c.primaryKey !== true)) {
      out.push(...this.columnXml('VariableColumn', column));
    }
    for (const key of table.foreignKeys ?? []) {
      out.push('        <ForeignKey>');
      for (const name of key.columns) out.push(`          <Name>${xmlText(name)}</Name>`);
      out.push(`          <References>${xmlText(key.references)}</References>`);
      out.push('        </ForeignKey>');
    }
    out.push('      </VariableLength>');
    out.push('    </Table>');
    return out;
  }

  private columnXml(element: string, column: Column): string[] {
    const out: string[] = [];
    out.push(`        <${element}>`);
    out.push(`          <Name>${xmlText(column.name)}</Name>`);
    out.push(`          <Description>${xmlText(column.description)}</Description>`);
    if (column.type.kind === 'alphanumeric') {
      out.push('          <AlphaNumeric />');
    } else if (column.type.kind === 'date') {
      out.push('          <Date>');
      // Explicitly ISO, because the standard's default is DD.MM.YYYY and summae writes dates
      // zoneless in ISO everywhere. YYYY-MM-DD is one of the formats the standard names.
      out.push('            <Format>YYYY-MM-DD</Format>');
      out.push('          </Date>');
    } else {
      out.push('          <Numeric>');
      out.push(`            <Accuracy>${column.type.accuracy}</Accuracy>`);
      out.push('          </Numeric>');
    }
    out.push(`        </${element}>`);
    return out;
  }
}
