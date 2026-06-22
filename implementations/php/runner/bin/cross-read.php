<?php

declare(strict_types=1);

/**
 * Cross-test, PHP read side Node→PHP (SF-15, reverse direction): opens the
 * `*.node.sqlite` written by Node (`cross-write.ts`), computes `journalExport` from
 * the persisted stock and writes the canonical result as
 * `*.php-actual.json`. The byte comparison against Node's oracle deliberately happens in
 * Node (`cross-read.ts`) — PHP's `json_decode(assoc)` would lose the {}/[] form.
 * Reading is config-/placeholder-free (journalExport only dumps the journal).
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\CanonicalJson;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\UuidV7IdGenerator;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\Schema\SchemaInstaller;
use Summae\Runner\FixtureLoader;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 4);

/** @var list<string> $argvList */
$argvList = $_SERVER['argv'] ?? [];
$dir = $root . '/.cross-dbs';
foreach (array_slice($argvList, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    }
}

$byName = [];
foreach ((new FixtureLoader())->discover($root . '/testsuite/fixtures') as $fixture) {
    $byName[$fixture->name] = $fixture;
}

$tables = ['accounts', 'journal_entries', 'fiscal_years', 'vouchers', 'partners', 'assets', 'open_items', 'audit_log'];

$files = glob($dir . '/*.node.sqlite') ?: [];
sort($files);
$written = 0;

foreach ($files as $dbFile) {
    $name = basename($dbFile, '.node.sqlite');
    /** @var array<string, mixed> $tenantData */
    $tenantData = isset($byName[$name]) && is_array($byName[$name]->setup['tenant'] ?? null)
        ? $byName[$name]->setup['tenant']
        : [];

    $capsule = new Capsule();
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => $dbFile,
        'foreign_key_constraints' => false,
    ]);
    $connection = $capsule->getConnection();

    $tenantId = null;
    foreach ($tables as $table) {
        $value = $connection->table(SchemaInstaller::PREFIX . $table)->value('tenant_id');
        if (is_string($value)) {
            $tenantId = $value;
            break;
        }
    }
    if ($tenantId === null) {
        fwrite(STDERR, "{$name}: no tenant_id in the DB\n");
        continue;
    }

    // Same fixed clock as Node's oracle ⇒ the exportedAt instant also matches.
    $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
    $tenant = (new DatabaseTenantFactory($connection))->build(
        is_string($tenantData['name'] ?? null) ? $tenantData['name'] : 'Cross',
        Currency::of(is_string($tenantData['baseCurrency'] ?? null) ? $tenantData['baseCurrency'] : 'EUR'),
        $clock,
        new UuidV7IdGenerator($clock),
        null,
        null,
        null,
        null,
        Uuid::fromString($tenantId),
    );

    $export = (new TenantOperations($tenant))->project('journalExport', ['format' => 'gobd-z3']);
    file_put_contents($dir . '/' . $name . '.php-actual.json', CanonicalJson::encode($export));
    $written++;
}

printf("Cross-read (PHP): %d results written → %s\n", $written, $dir);
