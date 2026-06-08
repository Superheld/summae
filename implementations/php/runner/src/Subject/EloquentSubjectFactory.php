<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner\Subject;

use Illuminate\Database\Capsule\Manager as Capsule;
use Rechnungswesen\Core\Tenant;
use Rechnungswesen\Laravel\EloquentTenantFactory;
use Rechnungswesen\Laravel\Schema\SchemaInstaller;

/**
 * Zweiter Runner-Lauf (JOB-012): dieselbe Konformitätssuite gegen den
 * Eloquent-Adapter. Je Fixture eine frische Datenbank — SQLite
 * in-memory per Default, Postgres über RW_DB_DRIVER=pgsql
 * (RW_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD).
 */
final class EloquentSubjectFactory implements SubjectFactory
{
    public function create(): Subject
    {
        return new CoreSubject(function (string $name, mixed ...$args): Tenant {
            $connection = $this->freshConnection();

            /** @var \Rechnungswesen\Core\Shared\Currency $currency */
            [$currency, $clock, $ids, $dimensions, $taxCodes, $taxProfile, $mappings] = $args + [
                1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null,
            ];

            /**
             * @var \Rechnungswesen\Core\Shared\Clock|null $clock
             * @var \Rechnungswesen\Core\Shared\IdGenerator|null $ids
             * @var \Rechnungswesen\Core\Ledger\DimensionRegistry|null $dimensions
             * @var \Rechnungswesen\Core\Tax\TaxCodeRegistry|null $taxCodes
             * @var \Rechnungswesen\Core\Tax\TaxProfile|null $taxProfile
             * @var \Rechnungswesen\Core\Mapping\MappingRegistry|null $mappings
             */
            return (new EloquentTenantFactory($connection))->build(
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
        $driver = getenv('RW_DB_DRIVER') ?: 'sqlite';

        $capsule = new Capsule();

        if ($driver === 'pgsql') {
            $capsule->addConnection([
                'driver' => 'pgsql',
                'host' => getenv('RW_DB_HOST') ?: 'postgres',
                'port' => getenv('RW_DB_PORT') ?: '5432',
                'database' => getenv('RW_DB_DATABASE') ?: 'rechnungswesen',
                'username' => getenv('RW_DB_USERNAME') ?: 'rechnungswesen',
                'password' => getenv('RW_DB_PASSWORD') ?: 'rechnungswesen',
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
