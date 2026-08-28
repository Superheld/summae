<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Timestamp;
use Summae\Core\Substrate\Uuid;

/**
 * The Z3 data carrier: flat files plus the index.xml that describes them (F-IO-012).
 *
 * **What this closes.** journalExport has always produced the *self-describing data set* — streams
 * plus a field catalogue — and datenformat.md stated the intent that "export in the
 * Beschreibungsstandard is a **mapping**, not an invention". The mapping itself was not in the
 * package, so an audit asking for a Z3 data carrier needed tooling summae did not ship, and
 * docs/gobd-conformance.md §10 carried that as its last open row. It is a mapping and nothing more:
 * every value here already exists in the books.
 *
 * **Why this is a projection in the core and not pack data.** It looks jurisdiction-specific, and it
 * is — but so are datevExport (German) and auditDataExport (the US AICPA standard), and both live
 * here. The rule the three follow: a **published exchange format** is core code selected by the
 * caller; what varies *inside* it by jurisdiction is pack data. This one takes no pack data at all —
 * it describes summae's own streams — so there is nothing for a pack to supply.
 *
 * **Standard: version 1.6 of 1 March 2019**, DTD gdpdu-01-03-2019.dtd. The structure follows the DTD
 * exactly, and the element order is not decoration: Table fixes (URL, Name?, Description?, Validity?,
 * codepage?, (DecimalSymbol, DigitGroupingSymbol)?, …, (VariableLength | FixedLength)), and an
 * importer rejects the file if they are shuffled.
 *
 * **Three decisions worth naming.**
 *
 * 1. **The journal is flattened to one row per line**, with the entry's header repeated. A CSV cannot
 *    nest, and an auditor's first act is to sum debit and credit per account — which needs the line,
 *    not the entry. entryId + lineNumber is the primary key, and both keys are declared, so the
 *    importer can join rather than being handed five unrelated files.
 * 2. **Nothing is written to disk**, because summae is a library and owns no file system. The tables
 *    come back as content, and the embedding writes them next to index.xml.
 * 3. **The DTD file itself is named, not shipped.** The standard requires it on the medium next to
 *    index.xml; it is the standard publisher's document, not ours, and a library that quietly
 *    redistributed a third party's normative file would be making a promise about its version that
 *    it cannot keep. notProvided says so rather than leaving it to be discovered at the audit desk.
 *
 * Mirror of the Node gdpdu-export.ts — byte-identical output is the contract (SF-15).
 */
final readonly class GdpduExportProjection
{
    private const string STANDARD = 'Beschreibungsstandard für die Datenträgerüberlassung 1.6 (2019-03-01)';
    private const string DTD = 'gdpdu-01-03-2019.dtd';

    /** `;` and `"` are the standard's own defaults; declared explicitly rather than relied on. */
    private const string COLUMN_DELIMITER = ';';
    private const string TEXT_ENCAPSULATOR = '"';
    private const string RECORD_DELIMITER = "\r\n";

    public function __construct(
        private Uuid $tenantId,
        private string $tenantName,
        private Currency $baseCurrency,
        private JournalRepository $journal,
        private AccountRepository $accounts,
        private VoucherRepository $vouchers,
        private PartnerRepository $partners,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $mediaName = $params['mediaName'] ?? null;
        if ($mediaName !== null && !is_string($mediaName)) {
            throw new DomainError('E_INPUT_INVALID', 'gdpduExport: "mediaName" must be a string', [
                'mediaName' => $mediaName,
            ]);
        }

        $fiscalYear = isset($params['fiscalYear']) && is_int($params['fiscalYear']) ? $params['fiscalYear'] : null;
        $tables = $this->tables($fiscalYear);

        $rendered = [];
        foreach ($tables as $table) {
            $rendered[] = [
                'url' => $table['url'],
                'name' => $table['name'],
                'rowCount' => count($table['rows']),
                'content' => self::csv($table['columns'], $table['rows']),
            ];
        }

        return [
            'standard' => self::STANDARD,
            'dtd' => self::DTD,
            'indexXml' => $this->indexXml($mediaName ?? 'Disk1', $tables),
            'tables' => $rendered,
            // The same shape systemDescription uses, and for the same reason: what a deliverable
            // does NOT contain is part of the deliverable.
            'notProvided' => [
                self::DTD . ' itself — the standard requires it on the medium beside index.xml; obtain it from the'
                    . ' publisher of the Beschreibungsstandard and place it there. summae names the version it wrote'
                    . ' against rather than redistributing a normative document it does not own.',
                'Writing the files. summae is a library and owns no file system: index.xml and every table come'
                    . ' back as content for the embedding to write.',
                'Document images. The carrier holds the bookkeeping data and the voucher REFERENCE; the voucher'
                    . " files themselves are the archive's, as everywhere else in summae.",
            ],
        ];
    }

    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * CSV with the standard's quoting: a value is wrapped when it carries the delimiter, a quote or
     * a line break, and an inner quote is doubled. Unconditional quoting would also be legal and is
     * not used, because a file an auditor may open by hand should be readable by hand.
     */
    private static function csvValue(string $value): string
    {
        $needsQuoting = str_contains($value, self::COLUMN_DELIMITER)
            || str_contains($value, self::TEXT_ENCAPSULATOR)
            || str_contains($value, "\r")
            || str_contains($value, "\n");
        if (!$needsQuoting) {
            return $value;
        }

        return self::TEXT_ENCAPSULATOR
            . str_replace(self::TEXT_ENCAPSULATOR, self::TEXT_ENCAPSULATOR . self::TEXT_ENCAPSULATOR, $value)
            . self::TEXT_ENCAPSULATOR;
    }

    /**
     * @param list<array{name: string, description: string, type: array{kind: string, accuracy?: int}, primaryKey?: bool}> $columns
     * @param list<list<string>> $rows
     */
    private static function csv(array $columns, array $rows): string
    {
        $out = [implode(self::COLUMN_DELIMITER, array_map(
            static fn (array $column): string => self::csvValue($column['name']),
            $columns,
        ))];
        foreach ($rows as $row) {
            $out[] = implode(self::COLUMN_DELIMITER, array_map(self::csvValue(...), $row));
        }

        // A trailing record delimiter, so the last row ends like every other one. An importer that
        // counts records by delimiter and one that counts by line then agree.
        return implode(self::RECORD_DELIMITER, $out) . self::RECORD_DELIMITER;
    }

    private static function xmlText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    /**
     * @return list<array{url: string, name: string, description: string, columns: list<array{name: string, description: string, type: array{kind: string, accuracy?: int}, primaryKey?: bool}>, rows: list<list<string>>, foreignKeys?: list<array{columns: list<string>, references: string}>}>
     */
    private function tables(?int $fiscalYear): array
    {
        $entries = $fiscalYear === null ? $this->journal->all() : $this->journal->forFiscalYear($fiscalYear);

        $accountNames = [];
        foreach ($this->accounts->all() as $account) {
            $data = $account->jsonSerialize();
            $accountNames[self::text($data['number'] ?? null)] = self::text($data['name'] ?? null);
        }
        $voucherNumbers = [];
        foreach ($this->vouchers->all() as $voucher) {
            $data = $voucher->jsonSerialize();
            $voucherNumbers[self::text($data['id'] ?? null)] = self::text($data['voucherNumber'] ?? null);
        }

        $scale = $this->baseCurrency->scale;
        $journalRows = [];
        foreach ($entries as $entry) {
            $data = $entry->jsonSerialize();
            $lines = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
            foreach ($lines as $index => $rawLine) {
                $line = is_array($rawLine) ? $rawLine : [];
                $money = is_array($line['money'] ?? null) ? $line['money'] : [];
                $tag = is_array($line['taxTag'] ?? null) ? $line['taxTag'] : null;
                $account = self::text($line['account'] ?? null);
                $journalRows[] = [
                    self::text($data['id'] ?? null),
                    (string) ($index + 1),
                    self::text($data['sequenceNumber'] ?? null),
                    self::text($data['entryDate'] ?? null),
                    self::text($data['voucherId'] ?? null),
                    $voucherNumbers[self::text($data['voucherId'] ?? null)] ?? '',
                    self::text($data['text'] ?? null),
                    $account,
                    $accountNames[$account] ?? '',
                    self::text($line['side'] ?? null),
                    self::text($money['amount'] ?? null),
                    self::text($money['currency'] ?? null),
                    $tag === null ? '' : self::text($tag['code'] ?? null),
                    $tag === null ? '' : self::text($tag['reportingKey'] ?? null),
                    self::text($data['status'] ?? null),
                    self::text($data['reverses'] ?? null),
                    self::text($data['reversedBy'] ?? null),
                    self::text($data['recordedAt'] ?? null),
                ];
            }
        }

        $alpha = ['kind' => 'alphanumeric'];
        $date = ['kind' => 'date'];

        $accountRows = [];
        foreach ($this->accounts->all() as $account) {
            $data = $account->jsonSerialize();
            $accountRows[] = [
                self::text($data['number'] ?? null),
                self::text($data['name'] ?? null),
                self::text($data['type'] ?? null),
                self::text($data['subtype'] ?? null),
                self::text($data['status'] ?? null),
            ];
        }

        $voucherRows = [];
        foreach ($this->vouchers->all() as $voucher) {
            $data = $voucher->jsonSerialize();
            $voucherRows[] = [
                self::text($data['id'] ?? null),
                self::text($data['voucherNumber'] ?? null),
                self::text($data['voucherDate'] ?? null),
                self::text($data['serviceDate'] ?? null),
                self::text($data['kind'] ?? null),
                self::text($data['partnerId'] ?? null),
            ];
        }

        $tables = [
            [
                'url' => 'journal.csv',
                'name' => 'Journal',
                'description' => 'Buchungssätze, eine Zeile je Buchungsposition (Journalfunktion)',
                'columns' => [
                    ['name' => 'entryId', 'description' => 'Eindeutige Buchungs-ID (UUIDv7)', 'type' => $alpha, 'primaryKey' => true],
                    ['name' => 'lineNumber', 'description' => 'Position innerhalb der Buchung, ab 1', 'type' => ['kind' => 'numeric', 'accuracy' => 0], 'primaryKey' => true],
                    ['name' => 'sequenceNumber', 'description' => 'Lückenlose Journalnummer je Geschäftsjahr', 'type' => ['kind' => 'numeric', 'accuracy' => 0]],
                    ['name' => 'entryDate', 'description' => 'Buchungsdatum (zonenlos)', 'type' => $date],
                    ['name' => 'voucherId', 'description' => 'Belegreferenz (Pflicht)', 'type' => $alpha],
                    ['name' => 'voucherNumber', 'description' => 'Belegnummer des referenzierten Belegs', 'type' => $alpha],
                    ['name' => 'text', 'description' => 'Buchungstext', 'type' => $alpha],
                    ['name' => 'accountNumber', 'description' => 'Kontonummer (führende Nullen signifikant)', 'type' => $alpha],
                    ['name' => 'accountName', 'description' => 'Kontobezeichnung zum Zeitpunkt des Exports', 'type' => $alpha],
                    ['name' => 'side', 'description' => 'debit|credit (Soll/Haben)', 'type' => $alpha],
                    ['name' => 'amount', 'description' => 'Betrag in Belegwährung, Vorzeichen positiv', 'type' => ['kind' => 'numeric', 'accuracy' => $scale]],
                    ['name' => 'currency', 'description' => 'ISO-4217-Code', 'type' => $alpha],
                    ['name' => 'taxCode', 'description' => 'Steuerschlüssel des Packs, leer wenn ungetaggt', 'type' => $alpha],
                    ['name' => 'taxReportingKey', 'description' => 'Meldeschlüssel (z. B. UStVA-Kennzahl)', 'type' => $alpha],
                    ['name' => 'status', 'description' => 'entered|finalized (Festschreibung)', 'type' => $alpha],
                    ['name' => 'reverses', 'description' => 'Bei Storno: ID der stornierten Buchung', 'type' => $alpha],
                    ['name' => 'reversedBy', 'description' => 'Bei stornierter Buchung: ID der Stornobuchung', 'type' => $alpha],
                    ['name' => 'recordedAt', 'description' => 'Erfassungszeitpunkt (kanonisch, UTC)', 'type' => $alpha],
                ],
                'rows' => $journalRows,
                'foreignKeys' => [
                    ['columns' => ['accountNumber'], 'references' => 'accounts.csv'],
                    ['columns' => ['voucherId'], 'references' => 'vouchers.csv'],
                ],
            ],
            [
                'url' => 'accounts.csv',
                'name' => 'Konten',
                'description' => 'Kontenplan (Kontenfunktion)',
                'columns' => [
                    ['name' => 'number', 'description' => 'Kontonummer', 'type' => $alpha, 'primaryKey' => true],
                    ['name' => 'name', 'description' => 'Kontobezeichnung', 'type' => $alpha],
                    ['name' => 'type', 'description' => 'asset|liability|equity|expense|revenue', 'type' => $alpha],
                    ['name' => 'subtype', 'description' => 'Kanonischer Subtyp (bank, cash, ar, ap, tax_in, …)', 'type' => $alpha],
                    ['name' => 'status', 'description' => 'active|locked', 'type' => $alpha],
                ],
                'rows' => $accountRows,
            ],
            [
                'url' => 'vouchers.csv',
                'name' => 'Belege',
                'description' => 'Belegstammdaten; die Belegbilder selbst gehören dem Archiv',
                'columns' => [
                    ['name' => 'voucherId', 'description' => 'Eindeutige Beleg-ID', 'type' => $alpha, 'primaryKey' => true],
                    ['name' => 'voucherNumber', 'description' => 'Belegnummer', 'type' => $alpha],
                    ['name' => 'voucherDate', 'description' => 'Belegdatum', 'type' => $date],
                    ['name' => 'serviceDate', 'description' => 'Leistungsdatum, falls erfasst — maßgeblich für die Steuerregelversion', 'type' => $date],
                    ['name' => 'kind', 'description' => 'Belegart', 'type' => $alpha],
                    ['name' => 'partnerId', 'description' => 'Geschäftspartner, falls zugeordnet', 'type' => $alpha],
                ],
                'rows' => $voucherRows,
            ],
        ];

        $partners = $this->partners->all();
        if ($partners !== []) {
            $partnerRows = [];
            foreach ($partners as $partner) {
                $data = $partner->jsonSerialize();
                $partnerRows[] = [
                    self::text($data['id'] ?? null),
                    self::text($data['name'] ?? null),
                    self::text($data['kind'] ?? null),
                    self::text($data['vatId'] ?? null),
                ];
            }
            $tables[] = [
                'url' => 'partners.csv',
                'name' => 'Geschaeftspartner',
                'description' => 'Debitoren/Kreditoren-Stammdaten',
                'columns' => [
                    ['name' => 'partnerId', 'description' => 'Eindeutige Partner-ID', 'type' => $alpha, 'primaryKey' => true],
                    ['name' => 'name', 'description' => 'Name des Geschäftspartners', 'type' => $alpha],
                    ['name' => 'kind', 'description' => 'customer|supplier|both', 'type' => $alpha],
                    ['name' => 'vatId', 'description' => 'USt-IdNr., falls erfasst', 'type' => $alpha],
                ],
                'rows' => $partnerRows,
            ];
        }

        $auditRows = [];
        foreach ($this->audit->all() as $record) {
            $data = $record->jsonSerialize();
            $changes = $data['changes'] ?? null;
            $auditRows[] = [
                self::text($data['id'] ?? null),
                self::text($data['at'] ?? null),
                self::text($data['actor'] ?? null),
                self::text($data['objectType'] ?? null),
                self::text($data['objectId'] ?? null),
                self::text($data['action'] ?? null),
                (string) json_encode($changes ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                self::text($data['previousRecordHash'] ?? null),
                self::text($data['recordHash'] ?? null),
            ];
        }
        $tables[] = [
            'url' => 'auditLog.csv',
            'name' => 'Aenderungsprotokoll',
            'description' => 'Audit-Trail; seit Format 0.8 hash-verkettet (Unveränderbarkeit prüfbar)',
            'columns' => [
                ['name' => 'recordId', 'description' => 'Eindeutige Satz-ID', 'type' => $alpha, 'primaryKey' => true],
                ['name' => 'at', 'description' => 'Änderungszeitpunkt (kanonisch, UTC)', 'type' => $alpha],
                ['name' => 'actor', 'description' => 'Vom Aufrufer gemeldete Identität; nie verifiziert', 'type' => $alpha],
                ['name' => 'objectType', 'description' => 'Betroffener Objekttyp; "redacted" = nach Löschrecht geleerte Hülle', 'type' => $alpha],
                ['name' => 'objectId', 'description' => 'Betroffenes Objekt', 'type' => $alpha],
                ['name' => 'action', 'description' => 'created|corrected|finalized|locked|…', 'type' => $alpha],
                ['name' => 'changes', 'description' => 'Vorher/Nachher-Diff der geänderten Felder (JSON)', 'type' => $alpha],
                ['name' => 'previousRecordHash', 'description' => 'SHA-256 des Vorgängersatzes (Hash-Kette)', 'type' => $alpha],
                ['name' => 'recordHash', 'description' => 'SHA-256 dieses Satzes', 'type' => $alpha],
            ],
            'rows' => $auditRows,
        ];

        return $tables;
    }

    /**
     * The index.xml, in the DTD's element order.
     *
     * Written by hand rather than through an XML library, deliberately and for the same reason the
     * core carries no framework: the file is small, its shape is fixed by a DTD, and the two
     * languages have to emit the **same bytes** — which a serialiser's own whitespace and attribute
     * habits would quietly prevent.
     *
     * @param list<array<string, mixed>> $tables
     */
    private function indexXml(string $mediaName, array $tables): string
    {
        $out = [];
        $out[] = '<?xml version="1.0" encoding="utf-8" standalone="no"?>';
        $out[] = '<!DOCTYPE DataSet SYSTEM "' . self::DTD . '">';
        $out[] = '<DataSet>';
        $out[] = '  <Version>1.0</Version>';
        $out[] = '  <DataSupplier>';
        $out[] = '    <Name>' . self::xmlText($this->tenantName) . '</Name>';
        $out[] = '    <Location>' . self::xmlText($this->tenantId->value) . '</Location>';
        $out[] = '    <Comment>summae, exportiert ' . self::xmlText(Timestamp::canonical($this->clock->now())) . '</Comment>';
        $out[] = '  </DataSupplier>';
        $out[] = '  <Media>';
        $out[] = '    <Name>' . self::xmlText($mediaName) . '</Name>';
        foreach ($tables as $table) {
            foreach ($this->tableXml($table) as $line) {
                $out[] = $line;
            }
        }
        $out[] = '  </Media>';
        $out[] = '</DataSet>';

        return implode("\n", $out) . "\n";
    }

    /**
     * @param array<string, mixed> $table
     *
     * @return list<string>
     */
    private function tableXml(array $table): array
    {
        /** @var list<array<string, mixed>> $columns */
        $columns = is_array($table['columns'] ?? null) ? array_values($table['columns']) : [];

        $out = [];
        $out[] = '    <Table>';
        $out[] = '      <URL>' . self::xmlText(self::text($table['url'] ?? null)) . '</URL>';
        $out[] = '      <Name>' . self::xmlText(self::text($table['name'] ?? null)) . '</Name>';
        $out[] = '      <Description>' . self::xmlText(self::text($table['description'] ?? null)) . '</Description>';
        $out[] = '      <UTF8 />';
        // Amounts are written the way summae stores them — a dot decimal and no grouping — so the
        // symbols are declared rather than left to the standard's German defaults, which would read
        // "1234.56" as one million.
        $out[] = '      <DecimalSymbol>.</DecimalSymbol>';
        $out[] = '      <DigitGroupingSymbol>,</DigitGroupingSymbol>';
        $out[] = '      <VariableLength>';
        $out[] = '        <ColumnDelimiter>' . self::xmlText(self::COLUMN_DELIMITER) . '</ColumnDelimiter>';
        $out[] = '        <RecordDelimiter>&#13;&#10;</RecordDelimiter>';
        $out[] = '        <TextEncapsulator>' . self::xmlText(self::TEXT_ENCAPSULATOR) . '</TextEncapsulator>';
        // The DTD requires every primary key BEFORE the ordinary columns, whatever their order in
        // the file: ((VariablePrimaryKey+, VariableColumn*) | (VariableColumn+)).
        foreach ($columns as $column) {
            if (($column['primaryKey'] ?? false) === true) {
                foreach ($this->columnXml('VariablePrimaryKey', $column) as $line) {
                    $out[] = $line;
                }
            }
        }
        foreach ($columns as $column) {
            if (($column['primaryKey'] ?? false) !== true) {
                foreach ($this->columnXml('VariableColumn', $column) as $line) {
                    $out[] = $line;
                }
            }
        }
        /** @var list<array{columns: list<string>, references: string}> $foreignKeys */
        $foreignKeys = is_array($table['foreignKeys'] ?? null) ? array_values($table['foreignKeys']) : [];
        foreach ($foreignKeys as $key) {
            $out[] = '        <ForeignKey>';
            foreach ($key['columns'] as $name) {
                $out[] = '          <Name>' . self::xmlText($name) . '</Name>';
            }
            $out[] = '          <References>' . self::xmlText($key['references']) . '</References>';
            $out[] = '        </ForeignKey>';
        }
        $out[] = '      </VariableLength>';
        $out[] = '    </Table>';

        return $out;
    }

    /**
     * @param array<string, mixed> $column
     *
     * @return list<string>
     */
    private function columnXml(string $element, array $column): array
    {
        /** @var array<string, mixed> $type */
        $type = is_array($column['type'] ?? null) ? $column['type'] : [];

        $out = [];
        $out[] = '        <' . $element . '>';
        $out[] = '          <Name>' . self::xmlText(self::text($column['name'] ?? null)) . '</Name>';
        $out[] = '          <Description>' . self::xmlText(self::text($column['description'] ?? null)) . '</Description>';
        if (($type['kind'] ?? '') === 'alphanumeric') {
            $out[] = '          <AlphaNumeric />';
        } elseif (($type['kind'] ?? '') === 'date') {
            $out[] = '          <Date>';
            // Explicitly ISO, because the standard's default is DD.MM.YYYY and summae writes dates
            // zoneless in ISO everywhere. YYYY-MM-DD is one of the formats the standard names.
            $out[] = '            <Format>YYYY-MM-DD</Format>';
            $out[] = '          </Date>';
        } else {
            $out[] = '          <Numeric>';
            $out[] = '            <Accuracy>' . self::text($type['accuracy'] ?? 0) . '</Accuracy>';
            $out[] = '          </Numeric>';
        }
        $out[] = '        </' . $element . '>';

        return $out;
    }
}
