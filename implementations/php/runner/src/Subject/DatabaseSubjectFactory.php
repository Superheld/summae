<?php

declare(strict_types=1);

namespace Summae\Runner\Subject;

use Illuminate\Database\Capsule\Manager as Capsule;
use Summae\Core\Tenant;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * Zweiter Runner-Lauf (JOB-012): dieselbe Konformitätssuite gegen den
 * Database-Adapter. Je Fixture eine frische Datenbank — SQLite
 * in-memory per Default, Postgres über SUMMAE_DB_DRIVER=pgsql
 * (SUMMAE_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD).
 */
final class DatabaseSubjectFactory implements SubjectFactory
{
    public function create(): Subject
    {
        return new CoreSubject(function (string $name, mixed ...$args): Tenant {
            $connection = $this->freshConnection();

            /** @var \Summae\Core\Substrate\Currency $currency */
            [$currency, $clock, $ids, $dimensions, $taxCodes, $taxProfile, $mappings] = $args + [
                1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null,
            ];

            /**
             * @var \Summae\Core\Substrate\Clock|null $clock
             * @var \Summae\Core\Substrate\IdGenerator|null $ids
             * @var \Summae\Core\Ledger\DimensionRegistry|null $dimensions
             * @var \Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry|null $taxCodes
             * @var \Summae\Core\Policies\Expansion\Tax\TaxProfile|null $taxProfile
             * @var \Summae\Core\Mapping\MappingRegistry|null $mappings
             */
            return (new DatabaseTenantFactory($connection))->build(
                $name,
                $currency,
                $clock,
                $ids,
                $dimensions,
                $taxCodes,
                $taxProfile,
                $mappings,
            );
        });
    }

    private function freshConnection(): \Illuminate\Database\Connection
    {
        $driver = getenv('SUMMAE_DB_DRIVER') ?: 'sqlite';

        $capsule = new Capsule();

        if ($driver === 'pgsql') {
            $capsule->addConnection([
                'driver' => 'pgsql',
                'host' => getenv('SUMMAE_DB_HOST') ?: 'postgres',
                'port' => getenv('SUMMAE_DB_PORT') ?: '5432',
                'database' => getenv('SUMMAE_DB_DATABASE') ?: 'summae',
                'username' => getenv('SUMMAE_DB_USERNAME') ?: 'summae',
                'password' => getenv('SUMMAE_DB_PASSWORD') ?: 'summae',
            ]);
        } else {
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => false,
            ]);
        }

        $connection = $capsule->getConnection();
        $schema = $connection->getSchemaBuilder();

        // Frischer Zustand je Fixture (Postgres: Tabellen neu aufbauen).
        SchemaInstaller::drop($schema);
        SchemaInstaller::create($schema);

        return $connection;
    }
}
