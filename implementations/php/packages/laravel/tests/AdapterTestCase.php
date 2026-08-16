<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\UuidV7IdGenerator;
use Summae\Core\Tenant;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * Shared setup for the adapter suite (NF-015): one in-memory SQLite connection per test with the
 * `summae_*` schema installed.
 *
 * SQLite in memory rather than Postgres so the suite runs anywhere `make test` runs — the
 * conformance runner already drives the adapter against Postgres (`--subject=database`), and the
 * SF-15 cross test drives it against a file database shared with Node. What those two cannot do is
 * assert anything *about the adapter itself*: they check that the numbers come out right, which
 * they would even if a repository leaked another tenant's rows or dropped a field on the way back.
 * That is what this suite is for.
 */
abstract class AdapterTestCase extends TestCase
{
    /** The concrete class, not the interface: `getSchemaBuilder()` is not part of `ConnectionInterface`. */
    protected Connection $connection;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);

        $this->connection = $capsule->getConnection();
        SchemaInstaller::create($this->schema());
    }

    /**
     * A tenant on the shared connection. Ids are real UUIDv7 rather than the deterministic
     * generator, because several tests put two tenants on one connection and the deterministic
     * generator would hand both of them the same primary keys.
     */
    protected function tenantOn(Uuid $tenantId, string $name = 'Adapter GmbH'): Tenant
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');

        return (new DatabaseTenantFactory($this->connection))->build(
            $name,
            Currency::of('EUR'),
            $clock,
            new UuidV7IdGenerator($clock),
            null,
            null,
            null,
            null,
            $tenantId,
        );
    }

    protected function schema(): \Illuminate\Database\Schema\Builder
    {
        return $this->connection->getSchemaBuilder();
    }

    /**
     * Raw row by primary key — the adapter's own repositories are what is under test, so
     * assertions about what actually landed in a column must not go through them.
     */
    protected function row(string $table, string $id): ?object
    {
        return $this->connection->table(SchemaInstaller::PREFIX . $table)->where('id', $id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonColumn(string $table, string $id, string $column): array
    {
        $row = $this->row($table, $id);
        self::assertNotNull($row, sprintf('no row %s in %s', $id, $table));

        $raw = $row->{$column} ?? null;
        self::assertIsString($raw, sprintf('%s.%s is not a JSON string', $table, $column));

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
