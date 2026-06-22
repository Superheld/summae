<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Records\AuditRecord;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\CanonicalJson;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Timestamp;
use Summae\Core\Substrate\Uuid;

/**
 * GoBD-Z3 export (SF-14): manifest with SHA-256 stream hashes over
 * RFC-8785-canonicalized rows, field catalog (self-describing,
 * datenformat.md principle 7), journal complete in
 * sequenceNumber order.
 *
 * auditLog stream: included as soon as the trail contains real change
 * history (actions beyond created/finalized) — the
 * fixture situation here is contradictory, see SPEC-FINDINGS.
 */
final readonly class JournalExportProjection
{
    private const string FORMAT_VERSION = '0.4';

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
     * @param array<string, mixed> $params fiscalYear, format
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : null;

        $entries = $fiscalYear === null ? $this->journal->all() : $this->journal->forFiscalYear($fiscalYear);

        $streams = [
            'journal' => array_map(self::formatEntry(...), $entries),
            'accounts' => array_map(
                static fn (\JsonSerializable $account): array => self::withoutNulls((array) $account->jsonSerialize()),
                $this->accounts->all(),
            ),
            'vouchers' => array_map(
                static fn (\JsonSerializable $voucher): array => self::withoutNulls((array) $voucher->jsonSerialize()),
                $this->vouchers->all(),
            ),
        ];

        if ($this->partners->all() !== []) {
            $streams['partners'] = array_map(
                static fn (object $partner): mixed => $partner->jsonSerialize(),
                $this->partners->all(),
            );
        }

        // v0.5/F-005: auditLog is ALWAYS part of the export (posting/finalizing
        // already creates entries — the trail survives system changes, SF-15).
        $streams['auditLog'] = array_map(
            static fn (AuditRecord $record): array => $record->jsonSerialize(),
            $this->audit->all(),
        );

        $contentHashes = [];
        foreach ($streams as $name => $rows) {
            $lines = array_map(static fn (mixed $row): string => CanonicalJson::encode($row), $rows);
            $contentHashes[(string) $name] = hash('sha256', implode("\n", $lines));
        }

        $allFinalized = true;
        foreach ($entries as $entry) {
            if (!$entry->isFinalized()) {
                $allFinalized = false;
                break;
            }
        }

        return [
            'manifest' => [
                // Schema $id stays 0.4 (additive fields); content-wise v0.5.
                'formatVersion' => self::FORMAT_VERSION,
                'tenantId' => $this->tenantId->value,
                'tenantName' => $this->tenantName,
                'baseCurrency' => $this->baseCurrency->code,
                'exportedAt' => Timestamp::canonical($this->clock->now()),
                'hashAlgorithm' => 'sha256',
                'streams' => array_map(strval(...), array_keys($streams)),
                'contentHashes' => $contentHashes,
            ],
            'fieldCatalogIncluded' => true,
            'fieldCatalog' => $this->fieldCatalog(),
            'journal' => [
                'entryCount' => count($entries),
                'ordering' => 'sequenceNumber',
                'allFinalized' => $allFinalized,
            ],
            'data' => $streams,
        ];
    }

    /**
     * Optional fields without a value do not belong in the exchange stream
     * (schema: additionalProperties false, optional properties typed).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $row): array
    {
        return array_filter($row, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Posting in the pure published-language form (format.schema.json,
     * additionalProperties: false): convenience fields of the operation results
     * (e.g. account number on the line) do not belong in the exchange format.
     *
     * @return array<string, mixed>
     */
    private static function formatEntry(JournalEntry $entry): array
    {
        $data = $entry->jsonSerialize();

        /** @var list<array<string, mixed>> $lines */
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $data['lines'] = array_map(
            static fn (array $line): array => array_intersect_key(
                $line,
                ['accountId' => true, 'side' => true, 'money' => true, 'dimensions' => true, 'taxTag' => true],
            ),
            $lines,
        );

        return $data;
    }

    /**
     * Field catalog (GoBD Z3 description standard): name, type, meaning.
     *
     * @return array<string, list<array{name: string, type: string, meaning: string}>>
     */
    private function fieldCatalog(): array
    {
        return [
            'journal' => [
                ['name' => 'id', 'type' => 'uuid', 'meaning' => 'Eindeutige Buchungs-ID (UUIDv7)'],
                ['name' => 'sequenceNumber', 'type' => 'integer', 'meaning' => 'Lückenlose Journalnummer je Geschäftsjahr'],
                ['name' => 'status', 'type' => 'string', 'meaning' => 'entered|finalized (Festschreibung)'],
                ['name' => 'entryDate', 'type' => 'date', 'meaning' => 'Buchungsdatum (zonenlos)'],
                ['name' => 'recordedAt', 'type' => 'timestamp', 'meaning' => 'Erfassungszeitpunkt'],
                ['name' => 'periodRef', 'type' => 'object', 'meaning' => 'Geschäftsjahr + Periode'],
                ['name' => 'voucherId', 'type' => 'uuid', 'meaning' => 'Belegreferenz (Pflicht)'],
                ['name' => 'text', 'type' => 'string', 'meaning' => 'Buchungstext'],
                ['name' => 'lines', 'type' => 'array', 'meaning' => 'Positionen: Konto, Seite, Betrag, Dimensionen, Steuer-Tag'],
                ['name' => 'reverses', 'type' => 'uuid|null', 'meaning' => 'Rückverweis bei Storno (Generalumkehr)'],
                ['name' => 'reversedBy', 'type' => 'uuid|null', 'meaning' => 'Verweis auf die Stornobuchung'],
            ],
            'accounts' => [
                ['name' => 'number', 'type' => 'string', 'meaning' => 'Kontonummer (führende Nullen signifikant)'],
                ['name' => 'name', 'type' => 'string', 'meaning' => 'Kontobezeichnung'],
                ['name' => 'type', 'type' => 'string', 'meaning' => 'asset|liability|equity|expense|revenue'],
                ['name' => 'subtype', 'type' => 'string|null', 'meaning' => 'Kanonischer Subtyp (bank, ar, ap, …)'],
            ],
            'vouchers' => [
                ['name' => 'voucherNumber', 'type' => 'string', 'meaning' => 'Belegnummer'],
                ['name' => 'voucherDate', 'type' => 'date', 'meaning' => 'Belegdatum'],
            ],
            'auditLog' => [
                ['name' => 'at', 'type' => 'timestamp', 'meaning' => 'Änderungszeitpunkt'],
                ['name' => 'actor', 'type' => 'string', 'meaning' => 'Audit-Identität'],
                ['name' => 'action', 'type' => 'string', 'meaning' => 'created|corrected|finalized|locked|…'],
                ['name' => 'changes', 'type' => 'object', 'meaning' => 'Vorher/Nachher-Diff der geänderten Felder'],
            ],
        ];
    }
}
