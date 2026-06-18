# summae — Handbuch

Konfiguration, Initialisierung und Nutzung der Pakete. Sprachübergreifend
geschrieben: dieselbe API, dasselbe Datenformat, byte-identisches Verhalten in
PHP und Node. Code-Beispiele stehen jeweils in beiden Sprachen.

**Inhalt**

1. [Überblick & mentales Modell](#1-überblick--mentales-modell)
2. [Installation](#2-installation)
3. [Initialisierung — einen Mandanten erzeugen](#3-initialisierung--einen-mandanten-erzeugen)
4. [Konfiguration](#4-konfiguration)
5. [Verwendung — Operationen & Projektionen](#5-verwendung--operationen--projektionen)
6. [Determinismus & Datenformat](#6-determinismus--datenformat)
7. [Fehlerbehandlung](#7-fehlerbehandlung)
8. [Weiterführend](#8-weiterführend)

---

## 1. Überblick & mentales Modell

summae ist eine **einbettbare Bibliothek**, keine Anwendung. Du baust einen
**Mandanten** (`Tenant`) und sprichst ihn über **einen einzigen Einstiegspunkt**
an: den Dispatcher `TenantOperations`. Er kennt zwei Methoden:

- `execute(op, input)` — **Schreiboperationen** (buchen, Stammdaten anlegen,
  abschließen …)
- `project(name, params)` — **lesende Projektionen** (SuSa, Bilanz, GuV, EÜR,
  USt-Voranmeldung, Export …)

Drei Invarianten prägen alles:

- **Das Journal ist append-only.** Salden werden nie gespeichert, sondern bei
  jeder Auswertung neu aus dem Journal berechnet. Eine Projektion ist immer eine
  frische Sicht, nie ein zwischengespeicherter Wert.
- **Geld ist nie ein Float.** Beträge laufen über einen exakten Dezimaltyp
  (`Money`), kaufmännisch gerundet (half-up, von Null weg).
- **Determinismus.** Gleiche Eingabe → byte-identisches Ergebnis. Uhr und
  ID-Generator sind injizierbar; in Produktion Systemuhr + UUIDv7, in Tests
  feste Uhr + deterministische IDs.

Wo die Daten liegen, bestimmt der **Port-Satz** des Mandanten — austauschbar,
ohne dass sich die Fachlogik ändert:

| Variante | Persistenz | Wofür |
|---|---|---|
| **In-Memory** | flüchtig (RAM) | Tests, Skripte, Konformitätsläufe |
| **Eloquent** (PHP/Laravel) | Datenbank (`summae_*`-Tabellen) | Produktion in Laravel-Apps |
| **CLI-Arbeitsbereich** (PHP) | lokale SQLite-Datei | Terminal/Automatisierung |

---

## 2. Installation

### PHP (Composer)

```bash
# Nur der framework-freie Kern
composer require superheld/summae-core

# Laravel-Integration (zieht core automatisch mit)
composer require superheld/summae-laravel

# Eigenständige CLI
composer require superheld/summae-cli
```

Voraussetzungen: **PHP ≥ 8.3** (empfohlen mit `bcmath`- oder `gmp`-Extension
für schnelle Dezimalarithmetik — läuft auch ohne, dann langsamer). Für die
Laravel-Integration zusätzlich Laravel 11 oder 12 und eine unterstützte
Datenbank (MySQL, MariaDB, PostgreSQL oder SQLite — engine-agnostisch).

Der Laravel-ServiceProvider wird über Package-Discovery automatisch registriert
— kein Eintrag in `config/app.php` nötig.

### Node (npm / pnpm / yarn)

```bash
pnpm add @superheld/summae-core      # oder: npm i / yarn add
```

Voraussetzung: **Node ≥ 22**. Das Paket wird dual ausgeliefert — **ESM**
(`import`) und **CJS** (`require`), inklusive Typdeklarationen. Einzige
Laufzeit-Abhängigkeit: `big.js`.

> **Aus dem Quellrepo statt Registry.** Solange ein Paket (noch) nicht in der
> Registry liegt, lässt es sich aus einem lokalen Klon einbinden:
>
> - **PHP** — in der `composer.json` deines Projekts ein Path-Repository
>   eintragen und per `@dev` anfordern:
>   ```json
>   "repositories": [
>     { "type": "path", "url": "/pfad/zu/summae/implementations/php/packages/*",
>       "options": { "symlink": false } }
>   ],
>   "minimum-stability": "dev",
>   "prefer-stable": true
>   ```
>   ```bash
>   composer require "superheld/summae-laravel:@dev"
>   ```
> - **Node** — im Klon `cd implementations/node && pnpm install && pnpm build`,
>   dann das gebaute Paket via `pnpm add /pfad/.../packages/core` oder
>   `npm pack` + lokale Installation einbinden.

---

## 3. Initialisierung — einen Mandanten erzeugen

Ein Mandant kapselt Kontenrahmen, Geschäftsjahre, Journal, Steuer- und
Regelmodul-Daten. Optionale Parameter (Dimensionen, Steuerschlüssel,
Steuerprofil, Mappings) haben sinnvolle Defaults und können später ergänzt
werden.

### In-Memory (PHP)

```php
use Summae\Core\Tenant;
use Summae\Core\Shared\Currency;
use Summae\Core\Composition\TenantOperations;

$tenant = Tenant::inMemory('Muster GmbH', Currency::of('EUR'));
$ops    = new TenantOperations($tenant);
// ohne Uhr/IdGenerator → SystemClock + UuidV7IdGenerator
```

### In-Memory (Node)

```ts
import {
  Tenant, Currency, TenantOperations,
  SystemClock, UuidV7IdGenerator,
} from '@superheld/summae-core';

const clock  = new SystemClock();
const tenant = Tenant.inMemory('Muster GmbH', Currency.of('EUR'), clock, new UuidV7IdGenerator(clock));
const ops    = new TenantOperations(tenant);
```

### Eloquent / Laravel (PHP, persistent)

```php
use Summae\Core\Shared\Currency;
use Summae\Core\Composition\TenantOperations;
use Summae\Laravel\EloquentTenantFactory;

// Factory aus dem Container; nutzt die konfigurierte DB-Connection (s. u.)
$tenant = app(EloquentTenantFactory::class)->build('Muster GmbH', Currency::of('EUR'));
$ops    = new TenantOperations($tenant);
```

Voraussetzung: `php artisan migrate` wurde ausgeführt (legt die `summae_*`-
Tabellen an, s. [Konfiguration](#4-konfiguration)).

### CLI-Arbeitsbereich (PHP)

```bash
# legt summae.json (Mandanten-Meta + Regeln) und summae.sqlite (Buchungen) an
summae init --name "Muster GmbH" --currency EUR --rules regeln.json --dir ./buchhaltung
```

`regeln.json` trägt die Regelmodul-Daten (Konten, Geschäftsjahre,
Steuerschlüssel, Mappings …). Jeder weitere Aufruf lädt den Mandanten aus dem
Arbeitsbereich, führt aus, und die SQLite-Datei persistiert.

---

## 4. Konfiguration

### Laravel: Datenbank

Das Package legt seine Tabellen über eine **Laravel-DB-Connection** an.
Standardmäßig die **Default-Connection** deiner App — du musst nichts weiter
einstellen, die `summae_*`-Tabellen landen in derselben Datenbank wie der Rest
deiner Anwendung. Zugangsdaten kommen an die gewohnte Stelle:

```dotenv
# .env (Standard-Laravel, nichts Package-Spezifisches)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=meinprojekt
DB_USERNAME=app
DB_PASSWORD=geheim
```

**Separate Datenbank für die Buchhaltung** (optional, z. B. aus Compliance-
Gründen): zweite Connection in `config/database.php` definieren und dem Package
zuweisen:

```php
// config/database.php → 'connections'
'buchhaltung' => [
    'driver'   => 'pgsql',
    'host'     => env('SUMMAE_DB_HOST', '127.0.0.1'),
    'port'     => env('SUMMAE_DB_PORT', '5432'),
    'database' => env('SUMMAE_DB_DATABASE', 'buchhaltung'),
    'username' => env('SUMMAE_DB_USERNAME'),
    'password' => env('SUMMAE_DB_PASSWORD'),
],
```

```dotenv
SUMMAE_DB_CONNECTION=buchhaltung   # die einzige Package-eigene Einstellung; leer = App-Default
```

Migration und (optionales) Veröffentlichen der Config:

```bash
php artisan migrate                              # legt die summae_*-Tabellen an
php artisan vendor:publish --tag=summae-config   # optional: config/summae.php (nur 'connection')
```

Die Migration ist im Package enthalten und wird automatisch gefunden — für die
Standardnutzung ist kein `vendor:publish` nötig.

### CLI: Arbeitsbereich

Die CLI braucht **keine** Datenbank-Zugangsdaten. Sie legt im Arbeitsverzeichnis
(`--dir`, Default: aktuelles Verzeichnis) zwei Dateien an:

| Datei | Inhalt |
|---|---|
| `summae.json` | Mandanten-Meta (Name, Währung, `tenantId`) + Regelmodul-Daten |
| `summae.sqlite` | die Buchungsdaten (Eloquent/SQLite) |

### Node / In-Memory: keine Konfiguration

Kein Persistenz-Setup. Steuerbar sind nur die Determinismus-Hooks (`Clock`,
`IdGenerator`) als Konstruktor-Parameter — siehe
[Determinismus](#6-determinismus--datenformat).

---

## 5. Verwendung — Operationen & Projektionen

Alles läuft über `TenantOperations`. Die Namen sind in allen Sprachen identisch.

### Operationen (`execute`)

`post`, `postVoucher`, `correct`, `finalize`, `reverse`, `settle`,
`closePeriod` / `reopenPeriod` / `closeFiscalYear`,
`createAccount` / `createFiscalYear`, `lockAccount`,
`importChartOfAccounts`, `importMapping`,
`expandTax` / `setTaxProfile`,
`acquireAsset` / `disposeAsset` / `runDepreciation`,
`setAllocationScheme` / `runCosting` / `releaseCosting`,
`createPartner` / `updatePartner`, `createTenant`.

### Projektionen (`project`)

`trialBalance`, `accountSheet`, `auditLog`, `openItems`, `vatReturn`,
`incomeStatement`, `balanceSheet`, `cashBasisReport`, `assetRegister`,
`costAllocationSheet`, `ecSalesList`, `journalExport`, `datevExport`.

### Durchgängiges Beispiel (PHP)

```php
// Stammdaten
$ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
$ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank',    'type' => 'asset',     'subtype' => 'bank']);
$ops->execute('createAccount', ['number' => '8400', 'name' => 'Erlöse',  'type' => 'revenue']);
$ops->execute('createAccount', ['number' => '1776', 'name' => 'USt 19%', 'type' => 'liability', 'subtype' => 'tax_out']);

// Buchen
$ops->execute('post', [
    'entryDate' => '2026-03-05',
    'voucherId' => $voucherId,                 // zuvor angelegter Beleg
    'text'      => 'Barverkauf',
    'lines'     => [
        ['account' => '1200', 'side' => 'debit',  'money' => ['amount' => '119.00', 'currency' => 'EUR']],
        ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
        ['account' => '1776', 'side' => 'credit', 'money' => ['amount' => '19.00',  'currency' => 'EUR']],
    ],
]);

// Auswerten
$susa = $ops->project('trialBalance', ['fiscalYear' => 2026, 'throughPeriod' => 12]);
```

### Dasselbe in Node

```ts
ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
ops.execute('createAccount', { number: '1200', name: 'Bank',    type: 'asset',     subtype: 'bank' });
ops.execute('createAccount', { number: '8400', name: 'Erlöse',  type: 'revenue' });
ops.execute('createAccount', { number: '1776', name: 'USt 19%', type: 'liability', subtype: 'tax_out' });

ops.execute('post', {
  entryDate: '2026-03-05', voucherId, text: 'Barverkauf',
  lines: [
    { account: '1200', side: 'debit',  money: { amount: '119.00', currency: 'EUR' } },
    { account: '8400', side: 'credit', money: { amount: '100.00', currency: 'EUR' } },
    { account: '1776', side: 'credit', money: { amount: '19.00',  currency: 'EUR' } },
  ],
});

const susa = ops.project('trialBalance', { fiscalYear: 2026, throughPeriod: 12 });
```

### Über die CLI

```bash
# Beleg + Steuerexpansion + Buchung in einem Aufruf
summae op postVoucher --dir ./buchhaltung --input '{
  "voucher": {"voucherNumber": "AR-001", "voucherDate": "2026-02-10"},
  "entryDate": "2026-02-10", "text": "Beratung",
  "taxCode": "USt19", "direction": "output",
  "netLines": [{"account": "8400", "money": {"amount": "1000.00", "currency": "EUR"}}],
  "counterAccount": "1200"
}'

summae report trialBalance --dir ./buchhaltung --params '{"fiscalYear": 2026, "throughPeriod": 12}'
```

`--input` / `--params` akzeptieren JSON direkt oder `@datei.json`.

> Steuerschlüssel, Kontenrahmen-Vorlagen, Bilanz-/GuV-/EÜR-Mappings und
> GWG-Grenzen sind **Regelmodul-Daten** (App-Schicht, versioniert). Sie werden
> dem Mandanten bei der Erzeugung als weitere Parameter mitgegeben
> (`TaxCodeRegistry`, `MappingRegistry`, …) bzw. bei der CLI über die
> `--rules`-Datei.

---

## 6. Determinismus & Datenformat

Gleiche Eingabe → byte-identisches Ergebnis, über Sprachen hinweg. Das macht
Ergebnisse reproduzierbar, testbar und zwischen Implementierungen austauschbar.

- **Uhr & IDs injizierbar.** In Produktion Systemuhr + UUIDv7
  (zeitlich sortierbar). In Tests feste Uhr + deterministischer ID-Generator —
  schreibe Tests **nie** gegen `now()` oder Zufall.

  ```php
  // PHP, deterministisch
  use Summae\Core\Shared\{FixedClock, DeterministicIdGenerator};
  $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
  $tenant = Tenant::inMemory('Demo', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));
  ```
  ```ts
  // Node, deterministisch
  import { FixedClock, DeterministicIdGenerator } from '@superheld/summae-core';
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = Tenant.inMemory('Demo', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
  ```

- **Buchungsdatum ist zonenlos** (Kalendertag, kein Zeitstempel mit UTC-Shift).
- **Sortierung** nach Unicode-Codepoints, **JSON** kanonisch (RFC 8785) — die
  maßgebliche Serialisierung erzeugt `CanonicalJson::encode(...)` (PHP) bzw.
  `canonicalJson(...)` (Node).
- **Austausch zwischen Implementierungen** läuft über das JSON-Datenformat
  (`journalExport` / Import), nicht über zwei lebende Engines auf derselben
  Live-DB. Eine andere Implementierung darf dieselbe Datenbank **lesen**;
  gleichzeitiges Schreiben durch zwei Engines auf dasselbe Journal ist bewusst
  zu vermeiden.

---

## 7. Fehlerbehandlung

Fachfehler werfen einen `DomainError` mit stabilem Code aus dem Fehlerkatalog
(`E_…`). Fange ihn und werte den Code aus, statt auf Meldungstexte zu prüfen:

```php
use Summae\Core\DomainError;
try {
    $ops->execute('post', $input);
} catch (DomainError $e) {
    // $e->errorCode → z. B. 'E_UNBALANCED_ENTRY'
    // $e->details   → beteiligte IDs/Werte; $e->getMessage() → freier Text
}
```

```ts
import { DomainError } from '@superheld/summae-core';
try {
  ops.execute('post', input);
} catch (e) {
  if (e instanceof DomainError) { /* e.errorCode → 'E_…', e.details, e.message */ }
}
```

Die **CLI** bildet denselben Katalog auf Exit-Codes ab: bei Fehlern gibt sie
`{"error": "E_…", "message": …, "details": …}` aus und beendet mit einem
Exit-Code ≥ 10 (stabile Abbildung, siehe `ExitCodes`).

---

## 8. Weiterführend

- **PHP:** [implementations/php/README.md](../../implementations/php/README.md)
  · Package-READMEs ([core](../../implementations/php/packages/core/README.md),
  [laravel](../../implementations/php/packages/laravel/README.md),
  [cli](../../implementations/php/packages/cli/README.md))
  · [Entwickler-Doku](../../implementations/php/docs/README.md)
- **Node:** [implementations/node/README.md](../../implementations/node/README.md)
  · [core](../../implementations/node/packages/core/README.md)
- **Kompatibilitätsvertrag:** `testsuite/` (Fixtures + Schema) — die normative
  Quelle, gegen die jede Implementierung byte-identisch geprüft wird.
