<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * Gate for the half of the shared data format that never leaves through `journalExport` (IMPL-046).
 *
 * The root CLAUDE.md calls the adapters' JSON "the shared data format", and the contract obligations
 * say anything the engine reads is validated against `format.schema.json`. For two years that was
 * true of the exchange format and false of everything the adapters store as an aggregate: assets and
 * costing runs were undeclared, and on 2026-08-29 provisions, deferrals and inventory valuations
 * joined them — three new document kinds in a day, in a format that did not know they existed. The
 * cost was not theoretical: the first comparison of two engines' stored documents found PHP writing
 * an empty map as `[]` where Node wrote `{}`.
 *
 * So this test asks the question nothing asked: **does every table the adapter creates hold a
 * document the format declares?** Deliberately the *table* list rather than a hand-kept list of
 * document types — a sixth aggregate arrives as a table, and a table nobody declared is exactly what
 * this must notice.
 *
 * Two tables are exceptions, and the exception carries its reason (IMPL-047). They are columnar
 * rather than one-document-per-row, and one of them (`tenants.config`) stores a shape that follows
 * its own input closely enough that declaring it is a decision rather than a paragraph. The
 * cross-test compares both byte-exact across engines, so they are unguarded only against a *schema*.
 *
 * The SAME checks live in the Node persisted-document-contract.test.ts.
 */
final class PersistedDocumentContractTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../..';

    /** table (without prefix) => the `$defs` keys describing the documents it stores. @var array<string, list<string>> */
    private const DECLARED = [
        'accounts' => ['account'],
        'journal_entries' => ['journalEntry'],
        'vouchers' => ['voucher'],
        'partners' => ['partner'],
        'open_items' => ['openItem'],
        'audit_log' => ['auditRecord'],
        'assets' => ['asset', 'assetState'],
        'costing_runs' => ['costingRun'],
        'provisions' => ['provision'],
        'deferrals' => ['deferral'],
        'inventory_valuations' => ['inventoryValuation'],
    ];

    /** @var array<string, string> */
    private const EXCEPTIONS = [
        'fiscal_years' => 'columnar; the `periods` column is JSON that no $defs entry describes — IMPL-047',
        'tenants' => 'columnar; `config` carries the tenant configuration (SPEC-015) and follows the operation input closely — IMPL-047',
    ];

    /** @return list<string> */
    private static function installedTables(): array
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);
        $connection = $capsule->getConnection();
        SchemaInstaller::create($connection->getSchemaBuilder());

        /** @var list<object{name: string}> $rows */
        $rows = $connection->select("select name from sqlite_master where type = 'table'");

        $tables = [];
        foreach ($rows as $row) {
            $name = $row->name;
            if (str_starts_with($name, SchemaInstaller::PREFIX)) {
                $tables[] = substr($name, strlen(SchemaInstaller::PREFIX));
            }
        }
        sort($tables);

        return $tables;
    }

    /** @return list<string> */
    private static function schemaDefs(): array
    {
        $raw = (string) file_get_contents(self::REPO_ROOT . '/testing/testsuite/schema/format.schema.json');
        /** @var array{'$defs'?: array<string, mixed>} $schema */
        $schema = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return array_map(strval(...), array_keys($schema['$defs'] ?? []));
    }

    public function testTheSchemaInstallsAndIsRead(): void
    {
        self::assertGreaterThan(10, count(self::installedTables()));
        self::assertGreaterThan(20, count(self::schemaDefs()));
    }

    public function testEveryTableTheAdapterCreatesHasADeclaredDocument(): void
    {
        $undeclared = array_values(array_filter(
            self::installedTables(),
            static fn (string $table): bool => !isset(self::DECLARED[$table]) && !isset(self::EXCEPTIONS[$table]),
        ));

        self::assertSame(
            [],
            $undeclared,
            'these tables store documents the format does not declare and no exception covers (IMPL-046)',
        );
    }

    public function testTheMapNamesOnlyExistingDefinitions(): void
    {
        $defs = self::schemaDefs();
        $missing = [];
        foreach (self::DECLARED as $names) {
            foreach ($names as $name) {
                if (!in_array($name, $defs, true)) {
                    $missing[] = $name;
                }
            }
        }

        self::assertSame([], $missing, 'the map points at $defs the schema does not have');
    }

    public function testEveryExceptionStillHasItsTable(): void
    {
        $tables = self::installedTables();
        $stale = array_values(array_filter(
            array_keys(self::EXCEPTIONS),
            static fn (string $table): bool => !in_array($table, $tables, true),
        ));

        self::assertSame([], $stale, 'an exception outlived its table — delete it rather than carrying it');
    }
}
