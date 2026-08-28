<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Substrate\Side;
use Summae\Laravel\Repository\Hydrator;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * IMPL-015: the two pieces every repository leans on.
 *
 * `Hydrator` is where the shared data format is actually produced and consumed — PHP writes these
 * JSON documents, Node reads them (SF-15). Its defensive branches (a column that is not a string, a
 * line without dimensions, a missing tax tag) never fire in the happy path the conformance runner
 * walks, which is exactly why they are worth pinning: a wrong default there does not crash, it
 * silently drops data.
 */
final class HydratorAndSchemaTest extends AdapterTestCase
{
    public function testMoneyFallsBackToTheDocumentedDefaultsRatherThanCrashing(): void
    {
        self::assertSame('12.34', Hydrator::money(['amount' => '12.34', 'currency' => 'EUR'])->amountAsString());

        // A malformed document must not take the process down mid-read; zero EUR is the documented
        // fallback, and the amount is still validated by Money itself.
        $fallback = Hydrator::money([]);
        self::assertSame('0.00', $fallback->amountAsString());
        self::assertSame('EUR', $fallback->currency->code);
    }

    public function testEntryLinesCarryDimensionsAndTaxTagsBackOut(): void
    {
        $lines = Hydrator::entryLines([
            [
                'accountId' => '0195f000-0000-7000-8000-000000000001',
                'account' => '8400',
                'side' => 'credit',
                'money' => ['amount' => '100.00', 'currency' => 'EUR'],
                'dimensions' => [
                    ['type' => 'costCenter', 'code' => '100'],
                    ['type' => 'costCenter'],          // incomplete → skipped, not guessed
                    'not-an-array',
                ],
                'taxTag' => ['code' => 'USt19', 'reportingKey' => '81'],
            ],
            [
                'accountId' => '0195f000-0000-7000-8000-000000000002',
                'account' => '1200',
                'side' => 'debit',
                'money' => ['amount' => '100.00', 'currency' => 'EUR'],
            ],
        ]);

        self::assertCount(2, $lines);
        self::assertSame(Side::Credit, $lines[0]->side);
        self::assertCount(1, $lines[0]->dimensions, 'an incomplete dimension is dropped, not invented');
        self::assertSame('costCenter', $lines[0]->dimensions[0]->type);
        self::assertSame(['code' => 'USt19', 'reportingKey' => '81'], $lines[0]->taxTag);
        self::assertSame([], $lines[1]->dimensions);
        self::assertNull($lines[1]->taxTag);
    }

    public function testDateReadsTheDateHalfOfATimestampColumn(): void
    {
        // SQLite hands back "2026-03-01", Postgres "2026-03-01 00:00:00" for the same column.
        self::assertSame('2026-03-01', Hydrator::date('2026-03-01')?->iso);
        self::assertSame('2026-03-01', Hydrator::date('2026-03-01 00:00:00')?->iso);
        self::assertNull(Hydrator::date(null));
        self::assertNull(Hydrator::date(''));
        self::assertNull(Hydrator::date(1234));
    }

    public function testEncodeAndDecodeRoundTripUnescapedUtf8(): void
    {
        $encoded = Hydrator::encode(['text' => 'Erlöse € Ümläute']);
        self::assertStringContainsString('Erlöse € Ümläute', $encoded, 'UTF-8 stays readable in the column');
        self::assertSame(['text' => 'Erlöse € Ümläute'], Hydrator::decode($encoded));

        self::assertSame([], Hydrator::decode(null));
        self::assertSame([], Hydrator::decodeList(null));
        self::assertSame([['a' => 1]], Hydrator::decodeList('[{"a":1}]'));
    }

    public function testDecodeRefusesBrokenJsonInsteadOfReturningEmpty(): void
    {
        // A truncated column is corruption, not "no data": returning [] here would present a
        // posting with no lines as a valid posting.
        $this->expectException(\JsonException::class);
        Hydrator::decode('{"lines": [');
    }

    public function testSchemaInstallerCreatesAndDropsTheWholeSet(): void
    {
        $builder = $this->schema();
        foreach ([
            'accounts', 'fiscal_years', 'vouchers', 'journal_entries',
            'open_items', 'partners', 'assets', 'audit_log',
        ] as $table) {
            self::assertTrue($builder->hasTable(SchemaInstaller::PREFIX . $table), $table);
        }

        SchemaInstaller::drop($builder);

        foreach ([
            'accounts', 'fiscal_years', 'vouchers', 'journal_entries',
            'open_items', 'partners', 'assets', 'audit_log',
        ] as $table) {
            self::assertFalse($builder->hasTable(SchemaInstaller::PREFIX . $table), $table);
        }

        // drop is idempotent — a second run must not blow up on tables that are already gone.
        SchemaInstaller::drop($builder);
        self::assertFalse($builder->hasTable(SchemaInstaller::PREFIX . 'accounts'));
    }

    public function testAnExistingTableGainsANullableColumnInsteadOfBreakingOnTheNextInsert(): void
    {
        // The upgrade path, simulated the only way it can be: drop the table and recreate it in the
        // shape it had BEFORE the validity window existed, then run the installer again. `ensure`
        // alone would have left it exactly as it is here and the next `add()` would have failed on
        // an unknown column — which is what "by hand" meant in practice.
        $builder = $this->schema();
        $table = SchemaInstaller::PREFIX . 'accounts';

        $builder->drop($table);
        $builder->create($table, static function (\Illuminate\Database\Schema\Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('tenant_id')->index();
            $blueprint->string('number', 64);
            $blueprint->string('name');
            $blueprint->string('type', 16);
            $blueprint->string('subtype', 32)->nullable();
            $blueprint->string('status', 16)->default('active');
            $blueprint->unique(['tenant_id', 'number']);
        });
        self::assertFalse($builder->hasColumn($table, 'valid_from'), 'precondition: the old shape');

        SchemaInstaller::create($builder);

        self::assertTrue($builder->hasColumn($table, 'valid_from'));
        self::assertTrue($builder->hasColumn($table, 'valid_to'));

        // Idempotent: running it a third time must not try to add them again.
        SchemaInstaller::create($builder);
        self::assertTrue($builder->hasColumn($table, 'valid_to'));
    }

    public function testTheAccountNumberIsUniquePerTenantAndNotGlobally(): void
    {
        // The unique index is (tenant_id, number): two tenants must both be able to run a "1200".
        $table = SchemaInstaller::PREFIX . 'accounts';
        $row = static fn (string $id, string $tenant): array => [
            'id' => $id,
            'tenant_id' => $tenant,
            'number' => '1200',
            'name' => 'Bank',
            'type' => 'asset',
            'subtype' => 'bank',
            'status' => 'active',
        ];

        $this->connection->table($table)->insert($row('0195f000-0000-7000-8000-000000000011', 'aaaa'));
        $this->connection->table($table)->insert($row('0195f000-0000-7000-8000-000000000012', 'bbbb'));
        self::assertSame(2, $this->connection->table($table)->count());

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->connection->table($table)->insert($row('0195f000-0000-7000-8000-000000000013', 'aaaa'));
    }
}
