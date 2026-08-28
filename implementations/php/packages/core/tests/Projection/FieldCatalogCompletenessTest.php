<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Projection;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;

/**
 * `journalExport`'s field catalogue describes the streams COMPLETELY (IMPL-038).
 *
 * The catalogue is the self-description a GoBD Z3 data set owes an auditor: name, type, meaning per
 * field. Until 2026-08-28 it was a *selection* without saying so — 4 of the account's 8 fields, 2 of
 * the voucher's 12, 4 of the audit record's 9, no `voucherDate` on the posting, and the `partners`
 * stream missing entirely. Somebody reading the description and the data side by side found fields
 * in the data the description does not mention, which is precisely the situation a self-describing
 * data set exists to prevent.
 *
 * Nothing caught it: `io/journal-export-z3-current` pins `fieldCatalogIncluded` — a boolean — and
 * the catalogue is outside the content hashes, so it could drift as far as it liked while the whole
 * gate stayed green.
 *
 * The export below is built to carry every optional field on purpose. That is what makes the second
 * assertion work in the other direction as well: adding a field to a record without describing it
 * fails here, and describing a field the export cannot carry fails here too.
 *
 * Node twin: field-catalog-completeness.test.ts.
 */
final class FieldCatalogCompletenessTest extends TestCase
{
    /**
     * An export in which every optional field of every stream carries a value.
     *
     * @return array<string, mixed>
     */
    private static function richExport(): array
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $tenant = Tenant::inMemory('Prüfer GmbH', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));
        $ops = new TenantOperations($tenant);

        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        $ops->execute('createAccount', [
            'number' => '1200',
            'name' => 'Bank',
            'type' => 'asset',
            'subtype' => 'bank',
            'validFrom' => '2026-01-01',
            'validTo' => '2026-12-31',
        ]);
        $ops->execute('createAccount', ['number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue']);
        $ops->execute('createAccount', ['number' => '10000', 'name' => 'Kunde AG', 'type' => 'asset', 'subtype' => 'ar']);

        $partner = $ops->execute('createPartner', [
            'name' => 'Kunde AG',
            'kind' => 'customer',
            'vatId' => 'DE123456789',
            'paymentTermsDays' => 30,
            'accountNumbers' => ['10000'],
            'address' => ['city' => 'Köln'],
        ]);
        $partnerId = is_string($partner['id'] ?? null) ? $partner['id'] : '';

        $voucher = $ops->execute('createVoucher', ['voucher' => [
            'voucherNumber' => 'RE-1',
            'voucherDate' => '2026-03-01',
            'serviceDate' => '2026-02-28',
            'servicePeriod' => ['from' => '2026-02-01', 'to' => '2026-02-28'],
            'economicYear' => 2026,
            'due' => '2026-03-31',
            'recurring' => false,
            'issuer' => 'Kunde AG',
            'kind' => 'invoice',
            'partnerId' => $partnerId,
            'supplierTaxationMethod' => 'accrual',
        ]]);
        $voucherId = is_string($voucher['id'] ?? null) ? $voucher['id'] : '';

        $entry = $ops->execute('post', [
            'entryDate' => '2026-03-01',
            'voucherId' => $voucherId,
            'text' => 'Ausgangsrechnung',
            'lines' => [
                ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
                ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
            ],
        ]);
        $entryId = is_string($entry['id'] ?? null) ? $entry['id'] : '';

        // A reversal, so `reverses` on one entry and `reversedBy` on the other both carry a value.
        $ops->execute('reverse', ['entryId' => $entryId, 'entryDate' => '2026-03-02']);

        /** @var array<string, mixed> $export */
        $export = $ops->project('journalExport', []);

        return $export;
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<string>
     */
    private static function keysIn(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            foreach (is_array($row) ? array_keys($row) : [] as $key) {
                $keys[(string) $key] = true;
            }
        }
        $names = array_keys($keys);
        sort($names);

        return $names;
    }

    /**
     * @param list<mixed> $catalog
     *
     * @return list<string>
     */
    private static function describedNames(array $catalog): array
    {
        $names = [];
        foreach ($catalog as $field) {
            if (is_array($field) && is_string($field['name'] ?? null)) {
                $names[] = $field['name'];
            }
        }
        sort($names);

        return $names;
    }

    public function testTheExportCarriesEveryStreamThisTestClaimsToCheck(): void
    {
        $export = self::richExport();
        /** @var array<string, mixed> $data */
        $data = is_array($export['data'] ?? null) ? $export['data'] : [];

        self::assertSame(
            ['journal', 'accounts', 'vouchers', 'partners', 'auditLog'],
            array_map(strval(...), array_keys($data)),
            'this test is only worth its assertions if the export really carries all five streams',
        );
    }

    public function testTheCatalogueDescribesExactlyTheStreamsOnTheCarrier(): void
    {
        $export = self::richExport();
        /** @var array<string, mixed> $data */
        $data = is_array($export['data'] ?? null) ? $export['data'] : [];
        /** @var array<string, mixed> $catalog */
        $catalog = is_array($export['fieldCatalog'] ?? null) ? $export['fieldCatalog'] : [];
        /** @var array<string, mixed> $manifest */
        $manifest = is_array($export['manifest'] ?? null) ? $export['manifest'] : [];

        self::assertSame(array_keys($data), array_keys($catalog));
        self::assertSame($manifest['streams'] ?? null, array_map(strval(...), array_keys($catalog)));
    }

    public function testEveryFieldInEveryStreamIsDescribed(): void
    {
        $export = self::richExport();
        /** @var array<string, mixed> $data */
        $data = is_array($export['data'] ?? null) ? $export['data'] : [];
        /** @var array<string, mixed> $catalog */
        $catalog = is_array($export['fieldCatalog'] ?? null) ? $export['fieldCatalog'] : [];

        foreach ($data as $stream => $rows) {
            /** @var list<mixed> $rowList */
            $rowList = is_array($rows) ? array_values($rows) : [];
            /** @var list<mixed> $catalogList */
            $catalogList = is_array($catalog[$stream] ?? null) ? array_values($catalog[$stream]) : [];

            self::assertSame(
                self::keysIn($rowList),
                self::describedNames($catalogList),
                sprintf(
                    'stream "%s": the self-description and the data must name the same fields — a field in the '
                    . 'data the catalogue omits is what an auditor trips over; a field in the catalogue the data '
                    . 'cannot carry is the same defect mirrored',
                    (string) $stream,
                ),
            );
        }
    }

    /**
     * Every described field says what it is and what it means. A row with an empty `meaning` would
     * satisfy the completeness check above while telling a reader nothing.
     */
    public function testEveryDescribedFieldCarriesATypeAndAMeaning(): void
    {
        /** @var array<string, mixed> $catalog */
        $catalog = is_array(self::richExport()['fieldCatalog'] ?? null) ? self::richExport()['fieldCatalog'] : [];

        $empty = [];
        foreach ($catalog as $stream => $fields) {
            foreach (is_array($fields) ? $fields : [] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $name = is_string($field['name'] ?? null) ? $field['name'] : '?';
                foreach (['type', 'meaning'] as $key) {
                    $value = $field[$key] ?? null;
                    if (!is_string($value) || trim($value) === '') {
                        $empty[] = sprintf('%s.%s has no %s', (string) $stream, $name, $key);
                    }
                }
            }
        }

        self::assertSame([], $empty);
    }
}
