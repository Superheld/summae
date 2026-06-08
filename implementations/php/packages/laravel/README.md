# superheld/rechnungswesen-laravel

Laravel-Integration der Rechnungswesen-Bibliothek: GoBD-konforme Doppik, EÜR,
Umsatzsteuer, Anlagen und KLR — als einbettbares Package. Der framework-freie
Kern (`superheld/rechnungswesen-core`) kommt automatisch als Abhängigkeit mit;
du installierst **ein** Package.

## Voraussetzungen

- PHP ≥ 8.3 (empfohlen mit `bcmath`- oder `gmp`-Extension für schnelle
  Dezimalarithmetik — funktioniert auch ohne, dann langsamer)
- Laravel 11 oder 12
- Eine von Laravel unterstützte Datenbank: MySQL, MariaDB, PostgreSQL oder
  SQLite (das Package ist engine-agnostisch)

## Installation

Das Package ist (noch) nicht auf Packagist. Bis dahin per **Path-Repository**
aus einem lokalen Klon. In der `composer.json` deines Laravel-Projekts:

```json
"repositories": [
    {
        "type": "path",
        "url": "/pfad/zu/rechnungswesen/implementations/php/packages/*",
        "options": { "symlink": false }
    }
],
"minimum-stability": "dev",
"prefer-stable": true
```

`packages/*` registriert core + laravel + cli auf einmal; angefordert wird nur
`laravel`, `core` wird automatisch mit aufgelöst. `"symlink": false` **kopiert**
das Package nach `vendor/` (bei Paketänderungen `composer update` nötig);
`true` verlinkt es stattdessen live.

Dann:

```bash
composer require "superheld/rechnungswesen-laravel:@dev"
```

Der ServiceProvider wird über Laravels Package-Discovery automatisch
registriert — kein Eintrag in `config/app.php` nötig.

## Konfiguration

### Datenbank & Zugangsdaten

Das Package legt seine Tabellen (`rw_*`) über eine **Laravel-DB-Connection**
an. Die Zugangsdaten kommen aus deiner App — an die **gewohnte Stelle**:

```dotenv
# .env deines Laravel-Projekts (Standard-Laravel, nichts Package-Spezifisches)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=meinprojekt
DB_USERNAME=app
DB_PASSWORD=geheim
```

Standardmäßig nutzt das Package die **Default-Connection** deiner App. Du musst
also nichts weiter konfigurieren — die `rw_*`-Tabellen landen in derselben
Datenbank wie der Rest deiner Anwendung.

### Optional: eigene Connection für die Buchhaltung

Wenn die Buchhaltungsdaten in eine **separate Datenbank** sollen (z. B. aus
Mandanten-, Backup- oder Compliance-Gründen): eine zweite Connection in
`config/database.php` definieren …

```php
// config/database.php → 'connections'
'buchhaltung' => [
    'driver'   => 'pgsql',
    'host'     => env('RW_DB_HOST', '127.0.0.1'),
    'port'     => env('RW_DB_PORT', '5432'),
    'database' => env('RW_DB_DATABASE', 'buchhaltung'),
    'username' => env('RW_DB_USERNAME'),
    'password' => env('RW_DB_PASSWORD'),
    'charset'  => 'utf8',
],
```

… und dem Package sagen, dass es diese nehmen soll:

```dotenv
RECHNUNGSWESEN_DB_CONNECTION=buchhaltung
```

Das ist die **einzige** Package-eigene Einstellung. Leer/ungesetzt = App-Default.

### Config publizieren (optional)

```bash
php artisan vendor:publish --tag=rechnungswesen-config
```

Legt `config/rechnungswesen.php` an — dort steht genau dieser eine Wert
(`connection`). Für die Standardnutzung brauchst du das nicht.

### Migration

```bash
php artisan migrate
```

Legt die `rw_*`-Tabellen auf der gewählten Connection an. Die Migration ist im
Package enthalten und wird automatisch gefunden (kein `vendor:publish` nötig).

## Benutzung

Einstiegspunkt ist die `EloquentTenantFactory` (aus dem Container). Sie baut
einen **Mandanten** mit Datenbank-Persistenz; alle Operationen und
Projektionen laufen über `TenantOperations` (Namen exakt nach API-Spec):

```php
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Composition\TenantOperations;
use Rechnungswesen\Laravel\EloquentTenantFactory;

$tenant = app(EloquentTenantFactory::class)->build('Muster GmbH', Currency::of('EUR'));
$ops = new TenantOperations($tenant);

// Stammdaten
$ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
$ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank',     'type' => 'asset',   'subtype' => 'bank']);
$ops->execute('createAccount', ['number' => '8400', 'name' => 'Erlöse',   'type' => 'revenue']);
$ops->execute('createAccount', ['number' => '1776', 'name' => 'USt 19%',  'type' => 'liability','subtype' => 'tax_out']);

// Buchen
$ops->execute('post', [
    'entryDate' => '2026-03-05',
    'voucherId' => $voucherId,            // ein zuvor angelegter Beleg
    'text'      => 'Barverkauf',
    'lines'     => [
        ['account' => '1200', 'side' => 'debit',  'money' => ['amount' => '119.00', 'currency' => 'EUR']],
        ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
        ['account' => '1776', 'side' => 'credit', 'money' => ['amount' => '19.00',  'currency' => 'EUR']],
    ],
]);

// Auswerten (deterministisch, asOf-fähig)
$susa = $ops->project('trialBalance', ['fiscalYear' => 2026, 'throughPeriod' => 12]);
```

Steuerschlüssel, Kontenrahmen-Vorlagen, Bilanz-/GuV-/EÜR-Mappings und
GWG-Grenzen sind **Regelmodul-Daten** (App-Schicht, versioniert). Sie werden
der Factory bei Bedarf als weitere Parameter übergeben (`TaxCodeRegistry`,
`MappingRegistry`, …) — siehe `EloquentTenantFactory::build()`.

## Hinweis: mehrere Backends

Dieses Package ist die **PHP-Implementierung**. Geplant sind weitere
(Node, Python) mit *identischer API und identischem Datenformat*. Der
Austausch zwischen Implementierungen läuft über das JSON-Datenformat
(`journalExport`/Import), nicht über zwei lebende Engines auf einer Live-DB.
Lesen derselben Datenbank durch eine andere Implementierung ist unkritisch;
gleichzeitiges Schreiben durch zwei Engines auf dasselbe Journal ist bewusst
zu vermeiden (keine gemeinsame Regelinstanz).

## Lizenz

MIT — siehe [LICENSE](../../LICENSE).
