<?php

declare(strict_types=1);

/**
 * Cross-Test, Schreib-Seite (SF-15): Fährt jede Fixture mit `setup.tenant` gegen
 * eine SQLite-DATEI (Database-Adapter) und legt zusätzlich den kanonischen
 * `journalExport` als Erwartung ab. Die Node-Seite (`cross-read.ts`) öffnet
 * dieselbe Datei, berechnet ihrerseits `journalExport` und muss byte-identisch
 * herauskommen — Beweis, dass beide Sprachen denselben Datenbestand teilen.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Summae\Core\Substrate\CanonicalJson;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\Schema\SchemaInstaller;
use Summae\Runner\FixtureLoader;
use Summae\Runner\FixtureRunner;
use Summae\Runner\Subject\CoreSubject;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 4); // Repo-Root (geteilte testsuite/)

/** @var list<string> $argvList */
$argvList = $_SERVER['argv'] ?? [];
$dir = $root . '/.cross-dbs';
foreach (array_slice($argvList, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    }
}

if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
    fwrite(STDERR, "Ausgabeverzeichnis nicht anlegbar: {$dir}\n");
    exit(1);
}

$fixtures = (new FixtureLoader())->discover($root . '/testsuite/fixtures');
$written = 0;
$skipped = 0;

foreach ($fixtures as $fixture) {
    // Nur Fixtures mit persistierendem Setup-Mandanten (createTenant läuft in-memory).
    if (!isset($fixture->setup['tenant']) || !is_array($fixture->setup['tenant'])) {
        $skipped++;
        continue;
    }

    $dbFile = $dir . '/' . $fixture->name . '.sqlite';
    @unlink($dbFile);
    touch($dbFile); // Laravels SQLite-Connector verlangt eine existierende Datei

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
         * @var \Summae\Core\Ledger\DimensionRegistry|null $dimensions
         * @var \Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry|null $taxCodes
         * @var \Summae\Core\Policies\Expansion\Tax\TaxProfile|null $taxProfile
         * @var \Summae\Core\Mapping\MappingRegistry|null $mappings
         */
        return (new DatabaseTenantFactory($connection))->build(
            $name, $currency, $clock, $ids, $dimensions, $taxCodes, $taxProfile, $mappings,
        );
    });

    // Fixture fahren (setup + steps mit Placeholder-Auflösung) → Datei wird befüllt.
    (new FixtureRunner())->run($fixture, $subject);

    // Oracle: kanonischer journalExport aus dem persistierten Bestand.
    $export = $subject->project('journalExport', ['format' => 'gobd-z3']);
    file_put_contents($dir . '/' . $fixture->name . '.expected.json', CanonicalJson::encode($export));

    $written++;
}

printf("Cross-Export: %d Fixtures geschrieben, %d übersprungen (kein setup.tenant) → %s\n", $written, $skipped, $dir);
