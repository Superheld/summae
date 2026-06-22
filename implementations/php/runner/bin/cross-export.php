<?php

declare(strict_types=1);

/**
 * Cross-test, write side (SF-15): runs every fixture with `setup.tenant` against
 * a SQLite FILE (database adapter) and additionally stores the canonical
 * `journalExport` as expectation. The Node side (`cross-read.ts`) opens
 * the same file, computes `journalExport` on its part and must come out
 * byte-identical — proof that both languages share the same data stock.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Summae\Core\Substrate\CanonicalJson;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\Schema\SchemaInstaller;
use Summae\Runner\FixtureLoader;
use Summae\Runner\FixtureRunner;
use Summae\Runner\Subject\CoreSubject;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 4); // repo root (shared testsuite/)

/** @var list<string> $argvList */
$argvList = $_SERVER['argv'] ?? [];
$dir = $root . '/.cross-dbs';
foreach (array_slice($argvList, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    }
}

if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
    fwrite(STDERR, "Output directory not creatable: {$dir}\n");
    exit(1);
}

$fixtures = (new FixtureLoader())->discover($root . '/testsuite/fixtures');
$written = 0;
$skipped = 0;

foreach ($fixtures as $fixture) {
    // Only fixtures with a persisting setup tenant (createTenant runs in-memory).
    if (!isset($fixture->setup['tenant']) || !is_array($fixture->setup['tenant'])) {
        $skipped++;
        continue;
    }

    $dbFile = $dir . '/' . $fixture->name . '.sqlite';
    @unlink($dbFile);
    touch($dbFile); // Laravel's SQLite connector requires an existing file

    $capsule = new Capsule();
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => $dbFile,
        'foreign_key_constraints' => false,
    ]);
    $connection = $capsule->getConnection();
    SchemaInstaller::create($connection->getSchemaBuilder());

    $subject = new CoreSubject(function (string $name, mixed ...$args) use ($connection): \Summae\Core\Tenant {
        /** @var \Summae\Core\Substrate\Currency $currency */
        [$currency, $clock, $ids, $dimensions, $taxCodes, $taxProfile, $mappings] = $args + [
            1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null,
        ];

        /**
         * @var \Summae\Core\Substrate\Clock|null $clock
         * @var \Summae\Core\Substrate\IdGenerator|null $ids
         * @var \Summae\Core\Policies\Constraint\DimensionRegistry|null $dimensions
         * @var \Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry|null $taxCodes
         * @var \Summae\Core\Policies\Expansion\Tax\TaxProfile|null $taxProfile
         * @var \Summae\Core\Policies\Projection\Mapping\MappingRegistry|null $mappings
         */
        return (new DatabaseTenantFactory($connection))->build(
            $name, $currency, $clock, $ids, $dimensions, $taxCodes, $taxProfile, $mappings,
        );
    });

    // Run fixture (setup + steps with placeholder resolution) → file gets populated.
    (new FixtureRunner())->run($fixture, $subject);

    // Oracle: canonical journalExport from the persisted stock.
    $export = $subject->project('journalExport', ['format' => 'gobd-z3']);
    file_put_contents($dir . '/' . $fixture->name . '.expected.json', CanonicalJson::encode($export));

    $written++;
}

printf("Cross-export: %d fixtures written, %d skipped (no setup.tenant) → %s\n", $written, $skipped, $dir);
