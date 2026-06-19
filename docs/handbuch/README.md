# summae — Handbuch

Das **eine** Dokument für Konfiguration, Initialisierung und Nutzung der
summae-Pakete. Sprachübergreifend: dieselbe API, dasselbe Datenformat,
byte-identisches Verhalten in PHP und Node. Wo es hilft, stehen Beispiele in
beiden Sprachen; die **PHP-Implementierung ist die Referenz**, Node spiegelt sie
namensgleich.

> Die Paket-READMEs sind bewusst dünn und verweisen hierher — die vollständige
> Beschreibung lebt nur in diesem Handbuch.

**Inhalt**

1. [Überblick & mentales Modell](#1-überblick--mentales-modell)
2. [Installation](#2-installation)
3. [Initialisierung — einen Mandanten erzeugen](#3-initialisierung--einen-mandanten-erzeugen)
4. [Konfiguration](#4-konfiguration)
5. [Setup- & Regelmodul-Datenformat](#5-setup--regelmodul-datenformat)
6. [API-Referenz: Operationen](#6-api-referenz-operationen)
   - [6.1 Aufrufmodell](#61-aufrufmodell)
   - [6.2 Ledger-Schreiboperationen](#62-ledger-schreiboperationen)
   - [6.3 Steuer, Mapping & Partner](#63-steuer-mapping--partner)
   - [6.4 Anlagen & Kostenrechnung](#64-anlagen--kostenrechnung)
7. [API-Referenz: Projektionen](#7-api-referenz-projektionen)
8. [Value Objects](#8-value-objects)
9. [Fehlerkatalog](#9-fehlerkatalog)
10. [Determinismus & Datenformat](#10-determinismus--datenformat)
11. [Weiterführend](#11-weiterführend)

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

Voraussetzungen: **PHP ≥ 8.3** (empfohlen mit `bcmath`- oder `gmp`-Extension für
schnelle Dezimalarithmetik — läuft auch ohne, dann langsamer). Für die
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

> **Veröffentlichungs-Status.** Alle Pakete sind in den öffentlichen Registries
> gelistet (v0.1.0) — die Befehle oben (`composer require …` / `pnpm add …`)
> funktionieren direkt, ohne weitere Konfiguration.
>
> Falls du stattdessen aus dem Quellrepo arbeiten willst: Node im Klon mit
> `pnpm install && pnpm build`; PHP via Path-/VCS-Repository auf die
> Paket-Verzeichnisse bzw. die Split-Repos `Superheld/summae-{core,laravel,cli}`.

---

## 3. Initialisierung — einen Mandanten erzeugen

Es gibt zwei Wege, einen Mandanten zu erzeugen:

1. **`createTenant` (SF-01)** — die deklarative Bootstrap-Operation: Mandant wird
   aus einem **Profil** und versionierten **Regelmodul-Daten** angelegt und ist
   sofort buchbar (siehe [§ 5](#5-setup--regelmodul-datenformat) und
   [createTenant](#createtenant-bootstrap-operation-sf-01)).
2. **Programmatisch** über `Tenant::inMemory(...)` (Core, In-Memory-Ports) oder
   `EloquentTenantFactory::build(...)` (Laravel-Adapter, DB-Persistenz) — hier
   übergibt man die Registries (Steuerschlüssel, Mappings, …) als fertige
   Objekte selbst.

Optionale Parameter haben sinnvolle Defaults und können später ergänzt werden.

### In-Memory (PHP)

```php
use Summae\Core\Tenant;
use Summae\Core\Shared\Currency;
use Summae\Core\Composition\TenantOperations;

$tenant = Tenant::inMemory('Muster GmbH', Currency::of('EUR'));
$ops    = new TenantOperations($tenant);
// ohne Uhr/IdGenerator → SystemClock + UuidV7IdGenerator
```

`Tenant::inMemory(...)` — Parameter:

| Parameter | Typ | Pflicht | Default |
|---|---|---|---|
| `name` | `string` | **ja** | — |
| `baseCurrency` | `Currency` | **ja** | — |
| `clock` | `?Clock` | nein | `new SystemClock()` |
| `ids` | `?IdGenerator` | nein | `new UuidV7IdGenerator($clock)` |
| `dimensions` | `?DimensionRegistry` | nein | `DimensionRegistry::empty()` |
| `taxCodes` | `?TaxCodeRegistry` | nein | `TaxCodeRegistry::empty()` |
| `taxProfile` | `?TaxProfile` | nein | `TaxProfile::default()` (accrual, kein Kleinunternehmer, quarterly) |
| `mappings` | `?MappingRegistry` | nein | `MappingRegistry::empty()` |

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

// Factory aus dem Container; nutzt die konfigurierte DB-Connection (s. § 4)
$tenant = app(EloquentTenantFactory::class)->build('Muster GmbH', Currency::of('EUR'));
$ops    = new TenantOperations($tenant);
```

`EloquentTenantFactory::build(...)` hat dieselben Parameter wie `inMemory`, plus
einen zusätzlichen letzten Parameter `tenantId` (`?Uuid`, Default: frisch
generiert) — damit lässt sich eine bestehende Mandanten-ID wieder aufnehmen.
Voraussetzung: `php artisan migrate` wurde ausgeführt (s. § 4).

### CLI-Arbeitsbereich (PHP)

```bash
# legt summae.json (Mandanten-Meta + Regeln) und summae.sqlite (Buchungen) an
summae init --name "Muster GmbH" --currency EUR --rules regeln.json --dir ./buchhaltung
```

`regeln.json` trägt die Regelmodul-Daten (Konten, Geschäftsjahre,
Steuerschlüssel, Mappings …, siehe § 5). Jeder weitere Aufruf lädt den Mandanten
aus dem Arbeitsbereich, führt aus, und die SQLite-Datei persistiert.

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
`IdGenerator`) als Konstruktor-Parameter — siehe § 10.

---

## 5. Setup- & Regelmodul-Datenformat

Stammdaten kommen über zwei kombinierbare Stile in den Mandanten:

- **Profil-Stil** — `ruleModules.profiles[]` + `chartsOfAccounts[]` + `taxCodes[]`;
  daraus baut `createTenant` den Mandanten (CLI-`regeln.json`, Fixtures).
- **Direkter Stil** — bei programmatischer Erzeugung übergibt man die fertigen
  Registries (`TaxCodeRegistry`, `MappingRegistry`, `DimensionRegistry`,
  `TaxProfile`) an `inMemory`/`build`.

Die folgenden Strukturen sind das maßgebliche Format (aus Code + Fixtures).

### `profiles[]`

| Feld | Typ | Pflicht | Bedeutung |
|------|-----|---------|-----------|
| `id` | string | ja | Referenz aus `createTenant.input.profile` |
| `name` | string | — | Anzeigename |
| `version` | string | ja | wird in den Mandanten gepinnt (Output `profile.version`) |
| `chartOfAccounts` | string | ja | ID eines Eintrags in `chartsOfAccounts[]` |
| `taxCodes` | list\<string\> | — | Codes, die aus `taxCodes[]` expandiert werden |
| `mappings` | list | — | Gliederungs-Mappings |
| `defaults` | object | — | Steuer-Defaults → `TaxProfile` (siehe unten) |

### `chartsOfAccounts[]` + `accounts[]`

| Feld | Typ | Pflicht | Bedeutung |
|------|-----|---------|-----------|
| `id` | string | ja | vom Profil referenziert |
| `accounts[].number` | string | ja | Kontonummer (Codepoint-Vergleich, führende Nullen signifikant) |
| `accounts[].name` | string | ja | Kontobezeichnung |
| `accounts[].type` | string (enum) | ja | `asset`, `liability`, `equity`, `expense`, `revenue` |
| `accounts[].subtype` | string\|null | — | freier Marker, steuert u. a. die OP-Automatik |

`type` bestimmt die Saldenmechanik: `asset`/`liability`/`equity` sind
bestandsführend (tragen über Jahre vor), `expense`/`revenue` sind je
Geschäftsjahr. `subtype` ist im Code ein freier String (keine Enum-Prüfung); in
Fixtures belegt: `bank`, `cash`, `ar`, `ap`, `tax_in`, `tax_out`, `fixed_asset`,
`opening_balance`, `transit`.

```json
"chartsOfAccounts": [
  { "id": "coa-mini-test",
    "accounts": [
      { "number": "1200", "name": "Bank", "type": "asset", "subtype": "bank" },
      { "number": "8400", "name": "Erlöse 19%", "type": "revenue" },
      { "number": "1776", "name": "USt 19%", "type": "liability", "subtype": "tax_out" }
    ] }
]
```

### `taxCodes[]` mit `versions[]`

Ein Code bündelt zeitlich gestaffelte Versionen.

| Feld | Typ | Pflicht | Bedeutung |
|------|-----|---------|-----------|
| `code` | string | ja | Schlüssel (führend; eigene Codes vor DATEV) |
| `versions[].validFrom` | string (Datum) | ja | Beginn der Gültigkeit (zonenlos) |
| `versions[].validTo` | string\|null | — | Ende; `null` = offen |
| `versions[].rate` | string (Dezimal) | — | Steuersatz, z. B. `"19.00"`; Default `"0"` |
| `versions[].taxAccount` | string | — | Steuerkonto |
| `versions[].reportingKey` | string\|null | — | VA-Kennzahl (z. B. `"81"`, `"66"`, `"41"`) |
| `versions[].mechanism` | string | — | Default `"standard"`; belegt: `intra_community_supply`, `reverse_charge` |
| `versions[].inputTaxAccount` | string\|null | — | Vorsteuerkonto (z. B. Reverse-Charge) |
| `versions[].inputReportingKey` | string\|null | — | Vorsteuer-Kennzahl |
| `versions[].baseReportingKey` | string\|null | — | Bemessungsgrundlagen-Kennzahl |

```json
"taxCodes": [
  { "code": "USt19", "versions": [
      { "validFrom": "2024-01-01", "validTo": null, "rate": "19.00", "taxAccount": "1776", "reportingKey": "81" } ] },
  { "code": "igL", "versions": [
      { "validFrom": "2024-01-01", "validTo": null, "rate": "0.00", "mechanism": "intra_community_supply", "reportingKey": "41" } ] }
]
```

Zugriff auf einen undefinierten Schlüssel → `E_TAXCODE_UNKNOWN`.

### `taxProfile` / `defaults`

Direkt als `setup.tenant.taxProfile` oder als `profile.defaults`.

| Feld | Typ | Default | Bedeutung |
|------|-----|---------|-----------|
| `taxationMethod` | `"cash"` \| `"accrual"` | `accrual` | Ist-/Soll-Versteuerung (alles ≠ `"cash"` ⇒ accrual) |
| `vatPeriod` | `"monthly"` \| `"quarterly"` | `quarterly` | VA-Zeitraum |
| `smallBusiness` | bool \| list | `false` | Kleinunternehmer; als bool oder Segmentliste `[{validFrom, value}]` für unterjährigen Wechsel |

### `dimensionTypes[]` / `dimensionValues[]` / `dimensionRules[]`

| Block | Feld | Typ | Bedeutung |
|-------|------|-----|-----------|
| `dimensionTypes[]` | `code` | string | Typ-Code (z. B. `costCenter`) |
| `dimensionValues[]` | `typeCode` / `code` | string | Verweis auf Typ / Wert-Code (eindeutig je `typeCode:code`) |
| `dimensionRules[]` | `accountRange.from`/`.to` | string | Kontonummern-Bereich (Codepoint-Vergleich) |
| | `requiredDimension` | string | in diesem Bereich verpflichtender Typ |

Verstoß ⇒ `E_DIMENSION_INVALID` (unbekannter Typ/Wert oder fehlende
Pflichtdimension).

```json
"dimensionTypes": [ { "code": "costCenter", "name": "Kostenstelle" } ],
"dimensionValues": [ { "typeCode": "costCenter", "code": "A", "name": "Stelle A" } ],
"ruleModules": { "dimensionRules": [ { "accountRange": { "from": "4000", "to": "4999" }, "requiredDimension": "costCenter" } ] }
```

### `mappings[]`

Gliederungs-Mappings (Bilanz, GuV, EÜR-Kategorien). Knoten mit `children[]`
werden rekursiv aufgelöst, Blätter tragen `accounts[]` (Selektoren: Bereiche
`{from,to}` und/oder Einzelkonten `{numbers:[…]}`).

| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `id` | string | Mapping-ID (von Projektionen referenziert) |
| `kind` | string | `balance-sheet`, `income-statement`, `cash-basis-categories` |
| `version` | string | Version |
| `positions[].key` / `.label` | string | Positionsschlüssel / Anzeige (Default = key) |
| `positions[].side` | string\|null | am Wurzelknoten gesetzt, an Blätter vererbt |
| `positions[].accounts[]` | list | Konto-Selektoren |
| `positions[].includeNonCash` / `includesNetIncome` | bool | EÜR-/Bilanz-Flags |

```json
"mappings": [
  { "id": "test-bilanz", "kind": "balance-sheet", "version": "1",
    "positions": [
      { "key": "A.1", "label": "Liquide Mittel", "accounts": [ { "from": "1200", "to": "1299" } ] },
      { "key": "A.2", "label": "Forderungen", "accounts": [ { "from": "1400", "to": "1499" } ] }
    ] }
]
```

---

## 6. API-Referenz: Operationen

### 6.1 Aufrufmodell

Alle Schreiboperationen laufen über den Dispatcher:

```php
$tenantOperations->execute(string $op, array $input): array;   // PHP
```
```ts
tenantOperations.execute(op, input);                           // Node
```

Konventionen für den ganzen Abschnitt:

- Geldwerte sind immer Objekte `{"amount":"119.00","currency":"EUR"}`. Fremd­währung wird in v1 abgelehnt (es zählt die Mandantenwährung).
- Jeder Input darf optional `actor` (String) tragen → Audit-Trail, Default `"system"`.
- Fehler werden als `DomainError` mit `E_*`-Code geworfen (siehe § 9); beim Buchen kommt **nur der erste** Fehler in fester Prüfreihenfolge zurück.

#### createTenant (Bootstrap-Operation, SF-01)

Mandant per Profil anlegen — **kein** normaler `execute`-Op, sondern Bootstrap
über die `TenantFactory` (in Runner/CLI als `op: createTenant` dispatcht). Das
Profil verweist auf Kontenrahmen + Steuerschlüssel; die Factory expandiert
beides, pinnt die Profil-Version und legt optional das erste Geschäftsjahr an.

| Feld | Typ | Pflicht | Bedeutung |
|------|-----|---------|-----------|
| `name` | string | nein (Default `"Tenant"`) | Anzeigename |
| `baseCurrency` | string (ISO-4217) | nein (Default `"EUR"`) | Hauswährung |
| `profile` | string | **ja** | Profil-ID aus `profiles[]`; unbekannt → `E_PROFILE_UNKNOWN` |
| `firstFiscalYear` | int | nein | Bei `> 0` wird GJ `JJJJ-01-01…JJJJ-12-31` angelegt |

Output: `id`, `name`, `profile.{id,version}`, `accountCount`, `taxationMethod`.
Fehler: `E_PROFILE_UNKNOWN` (Profil **oder** dessen Kontenrahmen fehlt).

```json
{ "op": "createTenant",
  "input": { "name": "Mustermann Consulting", "baseCurrency": "EUR", "profile": "de-freiberufler-euer", "firstFiscalYear": 2026 },
  "expect": { "result": { "id": "$T1", "profile": { "id": "de-freiberufler-euer", "version": "2026.1" }, "accountCount": 3, "taxationMethod": "cash" } } }
```

### 6.2 Ledger-Schreiboperationen

#### post

Erfasst eine Buchung im Journal (append-only). Erzeugt automatisch offene Posten
bei Buchung auf AR-/AP-Konten (Soll auf `subtype:"ar"` → `receivable`, Haben auf
`subtype:"ap"` → `payable`).

| Feld | Typ | Pflicht | Bedeutung |
|------|-----|---------|-----------|
| `voucherId` | string (UUID) | ja | existierender Beleg; keine Buchung ohne Beleg |
| `entryDate` | string (`YYYY-MM-DD`) | ja | Buchungsdatum (zonenlos); bestimmt GJ + Periode |
| `lines` | array | ja | Buchungszeilen, mind. 2 |
| `text` | string | nein | Buchungstext (Default `""`) |

Buchungszeile (`lines[]`): `account` (string, ja), `side` (`"debit"`/`"credit"`,
ja), `money` (Money > 0, ja), `dimensions` (`[{type,code}]`, nein), `taxTag`
(object, nein).

**Prüfreihenfolge / Fehlercodes:** 1) Struktur `E_ENTRY_TOO_FEW_LINES`,
`E_ENTRY_INVALID_AMOUNT`; 2) Referenzen `E_ENTRY_NO_VOUCHER`,
`E_VOUCHER_UNKNOWN`, `E_ACCOUNT_UNKNOWN`, `E_ACCOUNT_LOCKED`,
`E_DIMENSION_INVALID`; 3) Bilanz `E_ENTRY_UNBALANCED`; 4) Zeit
`E_PERIOD_UNKNOWN`, `E_PERIOD_CLOSED`.

Output: serialisierte Buchung (`id`, `sequenceNumber`, `status`, `entryDate`,
`periodRef`, `lines[]`, `reverses`/`reversedBy`, …) plus `openItemsCreated[]`.

```json
// input
{ "entryDate": "2026-03-05", "voucherId": "$V1", "text": "Barverkauf",
  "lines": [
    { "account": "1200", "side": "debit",  "money": { "amount": "119.00", "currency": "EUR" } },
    { "account": "8400", "side": "credit", "money": { "amount": "100.00", "currency": "EUR" } },
    { "account": "1776", "side": "credit", "money": { "amount": "19.00",  "currency": "EUR" } }
  ] }
// → result.sequenceNumber: 1, result.status: "entered"
```

Buchung auf ein AR-Konto erzeugt `openItemsCreated: [{ "kind": "receivable", "money": {…} }]`.

#### postVoucher

Der Ein-Aufruf-Standardfall (SF-02/03): legt den **Beleg an**, **expandiert die
Steuer** aus Netto-Zeilen + `taxCode` und **bucht** in einem Schritt. Anders als
`post` liefert man Belegdaten und Netto-Zeilen; Brutto-Gegenkonto und
Steuerzeilen entstehen automatisch.

| Feld | Typ | Pflicht | Bedeutung |
|------|-----|---------|-----------|
| `voucher` | object | ja | Belegdaten |
| `voucher.voucherNumber` | string | ja | Belegnummer |
| `voucher.voucherDate` | string (Datum) | ja | fehlend/ungültig → `E_ENTRY_NO_VOUCHER` |
| `voucher.partnerId` | string | nein | muss existieren (`E_PARTNER_UNKNOWN`) |
| `taxCode` | string | nein | Steuerschlüssel für die Expansion |
| `direction` | string | nein | `"output"` (Default) oder `"input"` |
| `netLines` | array von `{account, money}` | nein | Netto-Zeilen |
| `counterAccount` | string | ja | Brutto-Gegenkonto (Bank/Forderung) |
| `entryDate` | string | nein | Default = `voucher.voucherDate` |

Output: `entry` (wie `post`), `openItemsCreated[]`, `grossTotal` (Money),
`taxLines[]`, `voucherId`.

```json
// input
{ "voucher": { "voucherNumber": "AR-001", "voucherDate": "2026-02-10" },
  "entryDate": "2026-02-10", "text": "Beratung Februar",
  "taxCode": "USt19", "direction": "output",
  "netLines": [ { "account": "8400", "money": { "amount": "1000.00", "currency": "EUR" } } ],
  "counterAccount": "1200" }
// → grossTotal: {"amount":"1190.00","currency":"EUR"} (Netto 1000 + 19% USt)
```

#### correct

Ändert Text und/oder Zeilen einer Buchung — nur im Status `entered`, mit
Audit-Trail (kein Löschen). `entryId` (ja), `text` (nein), `lines` (nein, ≥ 2 &
balanciert). Output: serialisierte (geänderte) Buchung. Fehler:
`E_ENTRY_UNKNOWN`, `E_ENTRY_FINALIZED`, plus die `lines`-Fehler von `post`.

#### finalize

Schreibt Buchungen fest (`entered` → `finalized`). Einzeln (`entryId`) oder als
Massenauslöser (`finalizeUntil`: alle bis einschließlich Datum). Idempotent.
Output: `{ "finalizedCount": <int> }`. Fehler: `E_ENTRY_UNKNOWN` (weder Feld
gesetzt oder unbekannte `entryId`).

```json
{ "finalizeUntil": "2026-01-31" }   // → { "finalizedCount": 1 }
```

#### reverse

Storno per Generalumkehr: neue Buchung mit Rückverweis (`reverses`), gleiche
Konten/Seiten, **negierte Beträge**. `entryId` (ja), `entryDate` (ja, offene
Periode), `text` (nein, Default `"Storno <seqNo>"`). Output: serialisierte
Stornobuchung; Original bekommt `reversedBy`. Fehler: `E_ENTRY_UNKNOWN`,
`E_ENTRY_ALREADY_REVERSED`, `E_PERIOD_UNKNOWN`, `E_PERIOD_CLOSED`.

```json
// input { "entryId": "$E1", "entryDate": "2026-02-03", "text": "Storno Bürobedarf" }
// → lines mit money "-240.00", reverses: "$E1"
```

#### settle

Gleicht offene Posten aus — explizite Zuordnung Zahlung → Posten, auch
teilweise, optional mit Differenz (Skonto/Ausfall/Kleindifferenz). `entryId`
(ja, Zahlungsbuchung), `allocations` (ja, ≥ 1), `actor` (nein).

Zuordnung (`allocations[]`): `openItemId` (ja), `money` (Money > 0, inkl.
Differenz, ja), `difference` (`{money, kind}` mit kind
`"discount"`/`"bad_debt"`/`"minor"`, nein).

Output: `{ "openItems": [ … ] }` (betroffene Posten mit `remaining`, `status`
∈ `open`/`partially_settled`/`settled`, `settlements[]`). Fehler:
`E_ENTRY_UNKNOWN`, `E_OPENITEM_UNKNOWN`, `E_SETTLEMENT_EXCEEDS_ITEM`,
`E_SETTLEMENT_DIFFERENCE_INVALID`. Validierung ist all-or-nothing.

```json
// Teilzahlung
{ "entryId": "$E2", "allocations": [ { "openItemId": "$OP1", "money": { "amount": "500.00", "currency": "EUR" } } ] }
// → remaining 690.00, status "partially_settled"

// mit Skonto
{ "entryId": "$E2", "allocations": [ { "openItemId": "$OP1",
  "money": { "amount": "1190.00", "currency": "EUR" },
  "difference": { "money": { "amount": "23.80", "currency": "EUR" }, "kind": "discount" } } ] }
// → remaining 0.00, status "settled"
```

#### createAccount

`number` (ja), `name` (ja), `type` (ja: asset/liability/equity/expense/revenue),
`subtype` (nein), `status` (nein: `active`/`locked`). Output: serialisiertes
Konto. Fehler: `E_ACCOUNT_NUMBER_TAKEN`, `E_COA_FORMAT_INVALID`.

#### importChartOfAccounts

Atomarer Kontenrahmen-Import: erst alles validieren, dann anlegen. `rows` (ja,
nicht leer; je Zeile Felder wie `createAccount`), `format` (nein, im Kern nicht
ausgewertet). Output: `{ "importedCount": <int> }`. Fehler:
`E_COA_FORMAT_INVALID`, `E_ACCOUNT_NUMBER_TAKEN` (auch Duplikat im Batch).

#### lockAccount

Sperrt ein Konto (`active` → `locked`); danach `E_ACCOUNT_LOCKED` bei `post`.
`number` (ja). Output: serialisiertes Konto mit `status:"locked"`. Fehler:
`E_ACCOUNT_UNKNOWN`.

#### createFiscalYear

`year` (ja), `start` (ja), `end` (ja). Ohne explizite Perioden 12 Monate.
Output: `{ "year": <int>, "periodCount": <int> }`. Fehler:
`E_FISCALYEAR_OVERLAP` (Datumsüberschneidung oder gleiches `year`).

#### closePeriod / reopenPeriod

`fiscalYear` (ja), `period` (ja). Schließen nur in Reihenfolge. Output:
`{ "fiscalYear", "period", "status" }` (`"closed"` bzw. `"open"`). Fehler:
`E_PERIOD_UNKNOWN`, `E_PERIOD_OUT_OF_ORDER` (nur close), `E_FISCALYEAR_CLOSED`.

#### closeFiscalYear

Reiner Statuswechsel — **keine** Abschlussbuchungen. Voraussetzung: alle
Perioden geschlossen **und** alle Buchungen festgeschrieben. `fiscalYear` (ja).
Output: `{ "fiscalYear", "status": "closed" }`. Fehler: `E_PERIOD_UNKNOWN`,
`E_PERIOD_OUT_OF_ORDER`, `E_FISCALYEAR_UNFINALIZED_ENTRIES`.

### 6.3 Steuer, Mapping & Partner

#### expandTax

Reine, seiteneffektfreie Funktion: expandiert Netto-Positionen zu vollständigen
Buchungszeilen inkl. Steuerzeilen, Steuer-Tags und Brutto-Summe (Vorstufe von
`postVoucher`); verändert keinen Zustand.

| Feld | Typ | Pflicht | Bedeutung |
|------|-----|---------|-----------|
| `date` | string (Datum) | ja | Belegdatum; Versionswahl, falls kein `serviceDate` |
| `serviceDate` | string (Datum) | nein | Leistungsdatum (§ 27 UStG); Vorrang bei Versionswahl |
| `direction` | string | nein | `output` (default, credit) oder `input` (debit) |
| `taxCode` | string | nein | Default-Schlüssel für Positionen ohne eigenen |
| `netLines` | array | ja | ≥ 1 Netto-Position (`account`, `money`, optional `taxCode`) |

Berechnung: Steuer **pro Beleg je Steuersatz** (Netto-Summe je Schlüssel,
einmal half-up runden — nicht positionsweise); Gruppen nach Steuerkonto
(Codepoints) sortiert. Kleinunternehmer → keine `taxLines`, `taxTag` = null,
`grossTotal` = Netto. Reverse-Charge → USt-credit + VSt-debit, `grossTotal` =
Netto. Innergemeinschaftliche Lieferung → steuerfrei, nur Kennzahl-Tag.

Output: `netLines[]` (mit `side`, `taxTag`), `taxLines[]`, `grossTotal`. Fehler:
`E_ENTRY_TOO_FEW_LINES`, `E_TAXCODE_UNKNOWN`, `E_TAXCODE_NO_VALID_VERSION`,
`E_ENTRY_INVALID_AMOUNT`.

```json
// input — drei Positionen à 0.33 → Steuer pro Beleg gerundet
{ "date": "2026-05-10", "taxCode": "USt19", "direction": "output",
  "netLines": [ {"account":"8400","money":{"amount":"0.33","currency":"EUR"}},
                {"account":"8400","money":{"amount":"0.33","currency":"EUR"}},
                {"account":"8400","money":{"amount":"0.33","currency":"EUR"}} ] }
// → taxLine 1776 credit 0.19 (0.99 × 19% = 0.1881 → 0.19), grossTotal 1.18
```

#### setTaxProfile

Setzt/ändert den Kleinunternehmer-Status zum Stichtag. ⚠ Im Code wertet
`setProfile()` nur den `smallBusiness`-Block aus; `taxationMethod`/`vatPeriod`
stammen aus der Tenant-Konfiguration (im Output dennoch enthalten).

`smallBusiness` (ja): `{ validFrom (ja), value (bool, default false) }`.
Output: das serialisierte `TaxProfile` (`taxationMethod`, `vatPeriod`,
`smallBusiness[]` nach `validFrom` sortiert). Fehler:
`E_PROFILE_RETROACTIVE_CONFLICT` (kein `validFrom` oder ab Stichtag bereits
festgeschriebene Buchungen).

```json
{ "smallBusiness": { "validFrom": "2026-07-01", "value": false } }
// → smallBusiness: [ {"validFrom":"2026-01-01","value":true}, {"validFrom":"2026-07-01","value":false} ]
```

#### importMapping

Importiert ein Gliederungs-Mapping (Bilanz/GuV/EÜR). Prüft jedes relevante Konto
gegen die Positionen; Überlappung ist Fehler, Lücken sind Warnungen. Input unter
`mapping`: `id` (ja), `kind` (ja), `version` (nein), `positions[]` (ja, Struktur
siehe § 5).

Output: `{ "imported": true, "id", "kind", "gapWarnings": [ { "account", "assignedTo": "_unassigned" } ] }`.
Fehler: `E_MAPPING_OVERLAP` (Konto in mehreren Positionen).

#### createPartner

Schlanke Partner-Stammdaten (OP-je-Partner, USt-IdNr., ZM, DATEV). Alle Felder
optional mit Defaults: `name` (`""`), `kind` (`customer`/`supplier`/`both`,
default `both`), `vatId`, `paymentTermsDays`, `accountNumbers[]`, `address`.
Output: serialisierter Partner mit generierter `id`. Schreibt Audit-Eintrag.

```json
{ "name": "Alpen Handel GmbH", "kind": "customer", "vatId": "ATU12345678", "paymentTermsDays": 30, "accountNumbers": ["1400"] }
```

#### updatePartner

Aktualisiert vorhandene Partner; nur geänderte Felder werden geschrieben (Diff im
Audit-Trail). `partnerId` (ja), `name`/`vatId`/`kind`/`paymentTermsDays` (nein).
`vatId: null` löscht die USt-IdNr.; `accountNumbers`/`address` werden hier nicht
geändert. Output: serialisierter Partner. Fehler: `E_PARTNER_UNKNOWN`.

### 6.4 Anlagen & Kostenrechnung

Die Anlagen-Operationen brauchen ein **Regelmodul** im Mandanten-Setup
(`ruleModule`) mit `gwgThresholds` (datierte GWG-Grenzen), `usefulLife`
(Nutzungsdauer je `assetClass` in Monaten) und `assetAccounts`
(`acquisitionCounterAccount`, `depreciationExpenseAccount`,
`gwgExpenseAccount`). Anlagen-Buchungen werden sofort festgeschrieben (GoBD); die
KLR ist ein eigener Rechnungskreis und lässt das Fibu-Journal unberührt.

```json
"ruleModule": {
  "gwgThresholds": [ { "validFrom": "2018-01-01", "validTo": null, "immediateMax": "800.00", "poolMin": "250.01", "poolMax": "1000.00" } ],
  "usefulLife": [ { "assetClass": "it-hardware", "months": 36 } ],
  "assetAccounts": { "acquisitionCounterAccount": "1200", "depreciationExpenseAccount": "4830", "gwgExpenseAccount": "4855" }
}
```

#### acquireAsset

Erfasst einen Zugang und entscheidet die GWG-Weiche.

| Feld | Typ | Pflicht | Bedeutung |
|---|---|---|---|
| `name` | string | nein | Bezeichnung |
| `assetClass` | string | ja bei Aktivierung | Schlüssel in `usefulLife` |
| `assetAccount` | string | ja | Aktivkonto |
| `acquisitionCost` | Money | ja | AHK |
| `acquiredOn` | string (Datum) | ja | bestimmt die GWG-Grenze |
| `voucherId` | string (UUID) | ja | Beleg (fehlt → `InvalidValue` ⚠) |
| `gwgChoice` | string | nein (`"auto"`) | sonst `capitalize`/`immediate_expense`/`pool` |

GWG-Weiche bei `auto`: AHK ≤ `immediateMax` → `immediate_expense`; `poolMin` ≤
AHK ≤ `poolMax` → `pool` (60 Monate, 1/5); sonst → `capitalize` (Nutzungsdauer
aus `usefulLife`). Output: serialisiertes Asset (`route`,
`usefulLifeMonths`, …; bei `immediate_expense` zusätzlich `expenseAccount`).
Fehler: `E_ASSET_UNKNOWN` (keine Nutzungsdauer), `E_ACCOUNT_UNKNOWN`.

```json
{ "name": "Laptop", "assetClass": "it-hardware", "assetAccount": "0420",
  "acquisitionCost": { "amount": "3000.00", "currency": "EUR" },
  "acquiredOn": "2026-07-01", "voucherId": "$V1", "gwgChoice": "auto" }
// → route "capitalize", usefulLifeMonths 36
```

#### disposeAsset

`assetId` (ja), `disposedOn` (ja), `proceeds`/`proceedsAccount` (nein, nur
zusammen wird gebucht), `bankAccount` (nein, Default
`acquisitionCounterAccount`), `voucherId` (nein). Output: serialisiertes Asset
mit `status:"disposed"`. Fehler: `E_ASSET_UNKNOWN`, `E_ASSET_DISPOSED`. ⚠ Keine
Fixture; aus Code belegt.

#### runDepreciation

AfA-Lauf, idempotent. `fiscalYear` (ja); mit `period` Monatslauf, ohne
Jahreslauf. Verteilung Largest-Remainder (Σ = AHK exakt). Output:
`{ "entriesCreated", "totalDepreciation" }` bzw. bei No-op
`{ "alreadyRun": true, "entriesCreated": 0 }`. Fehler: `E_PERIOD_UNKNOWN`.

```json
{ "fiscalYear": 2026 }   // → entriesCreated 1, totalDepreciation 500.00 (6/36 von 3000)
```

#### setAllocationScheme

Umlageschema (Stufenleiter). `method` (nein, default `"step_ladder"`), `steps[]`
(`sender` ja, `receivers[].code` ja, `receivers[].share` nein, default `"1"`).
Output: `{ "valid", "method", "stepCount" }`. Fehler: `E_COSTING_CYCLE`;
fehlender `sender` → `InvalidValue` ⚠.

```json
{ "method": "step_ladder", "steps": [ { "sender": "VW", "receivers": [ { "code": "FE", "share": "60" }, { "code": "VT", "share": "40" } ] } ] }
```

#### runCosting

Abrechnungslauf: Primärkosten aus Aufwandszeilen mit `costCenter`-Dimension,
dann Umlage. `fiscalYear` (ja), `period` (ja). Output:
`{ "runId", "status": "draft", "version" }`.

#### releaseCosting

Freigabe (`draft` → `released`). `runId` (ja). Output:
`{ "runId", "status": "released" }`. Fehler: `E_COSTING_RUN_UNKNOWN`,
`E_COSTING_RUN_RELEASED`.

---

## 7. API-Referenz: Projektionen

Aufruf: `project(name, params)`. Salden werden nie gespeichert, sondern bei jedem
Aufruf aus dem Journal neu berechnet. Sortierungen nach Unicode-Codepoints bzw.
`sequenceNumber`/Datum. Geld erscheint je Feld als Betrags-String (`"178.50"`)
oder als Money-Objekt — unten ausgewiesen. `asOf`/`throughPeriod` ermöglichen
Stichtags-Auswertungen.

### trialBalance — Summen- und Saldenliste (SuSa)

`fiscalYear` (ja), `throughPeriod` (nein, Default alle), `includeZeroBalances`
(nein, default false). `openingBalance` nur für bestandsführende Konten;
`balance = openingBalance + debitTotal − creditTotal`. Geld als Betrags-Strings.

```json
// params { "fiscalYear": 2026, "throughPeriod": 12 }
{ "rows": [ { "account": "1200", "openingBalance": "0.00", "debitTotal": "178.50", "creditTotal": "0.00", "balance": "178.50" } ] }
```

### accountSheet — Kontoblatt

`account` (ja, Nummer; unbekannt → `E_ACCOUNT_UNKNOWN`), `fiscalYear` (ja),
`throughPeriod` (nein). Output: `account`, `name`, `openingBalance`, `lines[]`
(je `sequenceNumber`, `entryDate`, `text`, `side`, `money` [Money],
`runningBalance`), `closingBalance`. ⚠ Shape aus Code (keine Fixture).

### auditLog — Änderungshistorie

`from`/`to` (nein, Datumsbereich inkl.). Output: `records[]` mit `id`, `at`
(ATOM mit Zone), `actor`, `objectType`, `objectId`, `action`, `changes`
(Map `feld → {from,to}`).

```json
// params { "from": "2026-01-01", "to": "2026-12-31" }
{ "records": [ { "objectType": "journalEntry", "action": "corrected",
  "changes": { "text": { "from": "Bürobedarf", "to": "Bürobedarf Januar" } } } ] }
```

### openItems — Offene-Posten-Liste

`asOf` (nein, Stichtag), `kind` (nein, `receivable`/`payable`), `partnerId`
(nein). Posten mit Restbetrag 0 zum Stichtag entfallen. Output: `items[]` mit
`id`, `kind`, `voucherNumber`, `money` (Original, Money), `remaining` (Money),
`status`.

```json
// params { "asOf": "2026-02-20", "kind": "receivable" }
{ "items": [ { "voucherNumber": "AR-2026-010", "remaining": { "amount": "690.00", "currency": "EUR" }, "status": "partially_settled" } ] }
```

### assetRegister — Anlageverzeichnis

`asOf` (nein, Stichtag). Output: `assets[]` mit Basisfeldern plus
`accumulatedDepreciation` (Money), `bookValue` (Money) und —
nur bei `route:"capitalize"` — `depreciationSchedule` (Map `months<N>to<M>` +
`total`).

```json
// params { "asOf": "2026-12-31" }
{ "assets": [ { "name": "Laptop", "acquisitionCost": { "amount": "3000.00", "currency": "EUR" },
  "accumulatedDepreciation": { "amount": "500.00", "currency": "EUR" },
  "bookValue": { "amount": "2500.00", "currency": "EUR" } } ] }
```

### costAllocationSheet — Betriebsabrechnungsbogen (BAB)

`runId` (ja; unbekannt → `E_COSTING_RUN_UNKNOWN`). ⚠ `fiscalYear`/`period` in
Fixtures vorhanden, aber nicht ausgewertet. Output: `runId`, `status`, `version`,
`primary[]` und `afterAllocation[]` (je `{costCenter, total}`), `grandTotal`
(Strings).

```json
// Verrechnungssumme 4000 bleibt erhalten, Sender VW endet bei 0
{ "primary": [ { "costCenter": "VW", "total": "1000.00" } ],
  "afterAllocation": [ { "costCenter": "VW", "total": "0.00" } ], "grandTotal": "4000.00" }
```

### vatReturn — Umsatzsteuer-Voranmeldung

`year` (ja), `quarter` (nein, 0/fehlend = Jahr), `asOf` (nein). Soll-Versteuerung
(accrual) zählt nach Buchungs-/Leistungsdatum; Ist-Versteuerung (cash) folgt den
OP-Ausgleichen (`settledAt`, Teilzahlungen anteilig). Output: `keys` (je
`reportingKey` → `{base, tax}`; `base` amtlich auf volle Euro abgerundet, `tax`
centgenau), `payload` (Money: Σ Ausgangssteuer − Σ Vorsteuer).

```json
// params { "year": 2026, "quarter": 2, "asOf": "2026-07-01" }
{ "keys": { "81": { "base": "1000.00", "tax": "190.00" }, "66": { "tax": "19.00" } },
  "payload": { "amount": "171.00", "currency": "EUR" } }
```

### incomeStatement — Gewinn- und Verlustrechnung (GuV)

`fiscalYear` (ja), `mapping` (ja, GuV-Mapping-ID; nicht geladen →
`E_MAPPING_OVERLAP` ⚠), `fromPeriod`/`throughPeriod` (nein). Vorzeichen
Haben − Soll; nur Erfolgskonten. Output: `positions[]` (`key`, `label`,
`amount`), `netIncome`.

```json
// params { "fiscalYear": 2026, "throughPeriod": 12, "mapping": "test-guv" }
{ "positions": [ { "key": "1", "label": "Umsatzerlöse", "amount": "1000.00" },
                 { "key": "2", "label": "Sonstige betriebliche Aufwendungen", "amount": "-300.00" } ],
  "netIncome": "700.00" }
```

### balanceSheet — Bilanz

`asOf` (nein, Stichtag), `mapping` (ja, Bilanz-Mapping-ID), `incomeMapping`
(nein; ⚠ wird von `compute()` nicht ausgewertet — das Jahresergebnis fließt über
das `includesNetIncome`-Blatt des Bilanz-Mappings ein). Seitenzuordnung über
`side`. Output: `assets[]`, `assetsTotal`, `liabilitiesAndEquity[]`,
`liabilitiesAndEquityTotal` — Bilanzidentität by construction.

```json
// params { "asOf": "2026-12-31", "mapping": "test-bilanz" }
{ "assets": [ { "key": "A.B", "amount": "890.00" } ], "assetsTotal": "890.00",
  "liabilitiesAndEquity": [ { "key": "P.EK", "amount": "700.00" }, { "key": "P.V", "amount": "190.00" } ],
  "liabilitiesAndEquityTotal": "890.00" }
```

### cashBasisReport — Einnahmen-Überschuss-Rechnung (EÜR)

`year` (ja), `asOf` (nein), `mapping` (nein; ohne Mapping greift der Kontoname).
Zahlungswirksamkeit über Geldkonten, 10-Tage-Regel, USt erfolgswirksam,
Anlagenzahlung nicht abziehbar. Abweichendes Geschäftsjahr →
`E_CASHBASIS_DEVIATING_FISCAL_YEAR`. Output: `income[]`/`expenses[]` (je
`{category, amount}`, nach Kategorie sortiert).

```json
// params { "year": 2025, "asOf": "2026-06-07" }
{ "income": [], "expenses": [ { "category": "USt-Zahlung an FA", "amount": "190.00" } ] }
```

### ecSalesList — Zusammenfassende Meldung (ZM)

`year` (ja), `quarter` (nein). Innergemeinschaftliche Lieferungen je USt-IdNr.
(aus den Kennzahl-Tags der igL-Schlüssel; Partner über den Beleg). Output:
`rows[]` (`vatId`, `amount`, `kind`). Buchungen ohne Partner-USt-IdNr. entfallen.

```json
// params { "year": 2026, "quarter": 1 }
{ "rows": [ { "vatId": "ATU12345678", "amount": "1000.00", "kind": "supply" } ] }
```

### journalExport — GoBD-Z3-Export

`fiscalYear` (nein; fehlend = ganzes Journal), `format` (nein, nicht
ausgewertet; `formatVersion` fix `"0.4"`). Output: `manifest`
(`formatVersion`, `tenantId`, `exportedAt`, `hashAlgorithm:"sha256"`, `streams`,
`contentHashes`), `fieldCatalog`, `journal` (`entryCount`, `ordering`,
`allFinalized`), `data` (`journal`, `accounts`, `vouchers`, `partners?`,
`auditLog`). `contentHashes` = SHA-256 über RFC-8785-kanonisierte Zeilen je
Stream. Der Audit-Trail ist immer Teil des Exports.

### datevExport — DATEV-Export

`kind` (nein: `entries` default / `accounts` / `partners`); für `entries`
zusätzlich `fiscalYear`/`fromPeriod`/`throughPeriod`. Output:
`{ "kind", "rows": [ … ], "rowCount" }`. Zeilen je `kind` unterschiedlich
(Stapelzeile: `amount`, `debitCredit`, `account`, `contraAccount`, `buKey`,
`documentField1`, `date` (MMTT), `text`, `finalized`). ⚠ Exaktes
EXTF-Headerformat noch gegen aktuelle DATEV-Doku zu verifizieren.

```json
// params { "fiscalYear": 2026, "fromPeriod": 1, "throughPeriod": 12 }
{ "rows": [ { "amount": "119.00", "debitCredit": "S", "account": "1200", "contraAccount": "8400",
  "buKey": "3", "documentField1": "AR-77", "date": "0303", "text": "Barverkauf", "finalized": true } ], "rowCount": 1 }
```

---

## 8. Value Objects

Alle Value Objects liegen im Namespace `Summae\Core\Shared` (Node: gleicher
Satz), sind unveränderlich und werden **nie per `new`** konstruiert, sondern über
statische Factories (`of`, `fromString`, …), die validieren und bei Verstoß
`InvalidValue` (bei Money zusätzlich `CurrencyMismatch`) werfen. Diese
Wert-/Format-Fehler sind **kein** Teil des fachlichen `E_*`-Katalogs.

### Money

Betrag (exakter Dezimalwert) + Währung. **Nie Float.** JSON-Shape:
`{"amount": "100.00", "currency": "EUR"}` — `amount` ist String mit fester Skala
(EUR: 2 Nachkommastellen).

```php
$m = Money::of('100.00', 'EUR');        // Skala MUSS passen; rundet NICHT
$z = Money::zero('EUR');
$m = Money::fromCalculation('2.225', 'EUR');   // → 2.23 (einziger Weg, auf dem gerundet wird)
```
```ts
Money.of("100.00", "EUR"); Money.zero("EUR"); Money.fromCalculation("2.225", "EUR");
```

Rundung: kaufmännisch **half-up, von Null weg** bei `.5` (kein banker's
rounding): `2.225 → 2.23`, `-2.345 → -2.35`.

Wichtige Methoden: `add`/`subtract` (wirft `CurrencyMismatch` bei abweichender
Währung), `negate`/`abs`, `compareTo`/`equals`, `isZero`/`isPositive`/
`isNegative`, `amountAsString`, `jsonSerialize`.

**`allocate(...$weights)` — Largest-Remainder.** Verteilt verlustfrei nach
Gewichten; **Invariante: Σ Teile = Ausgangsbetrag**. Restcent geht an die Teile
mit größtem Rest, bei Gleichstand an den kleinsten Index.
```php
Money::of('100.00', 'EUR')->allocate(1, 1, 1);   // → [33.34, 33.33, 33.33]
```
`allocateEvenly(int $parts)` teilt in `$parts` gleiche Teile (AfA-Raten,
Sammelposten-Fünftel). Fehler (leer/negativ/Summe 0) → `InvalidValue`.

### Currency

ISO-4217-Code + feste Skala. `Currency::of('EUR')` (scale 2). Default-Skala 2;
hinterlegt: JPY/KRW = 0, BHD/KWD/TND = 3. ⚠ v1 EUR-zentriert, keine echte
ISO-Vollprüfung (jeder formal gültige 3-Letter-Code wird akzeptiert). JSON: der
nackte Code-String `"EUR"`.

### CalendarDate

Zonenloses Kalenderdatum (ISO `Y-m-d`). `CalendarDate::of('2026-06-18')`;
`isBefore`/`isAfter`/`isBetween`, `year`/`month`, `lastDayOfMonth`/
`firstDayOfNextMonth`. Strenge Prüfung (`2026-02-30` → `InvalidValue`). JSON: der
ISO-String.

### AccountNumber

Kontonummer als String — **führende Nullen signifikant**, Vergleich nach
Unicode-Codepoints (`"0420" < "1200" < "8400"`, `"10" < "9"`). 1–64 Zeichen, kein
Whitespace/Steuerzeichen. JSON: der String.

### Uuid

UUIDv7 (RFC 9562) — als String zeitlich sortierbar. `Uuid::fromString(...)`
(normalisiert lowercase), `Uuid::v7([$clock])`. JSON: kanonischer
Lowercase-String. Fixtures vergleichen nie ID-Werte, nur Platzhalter-Gleichheit.

### Clock / IdGenerator — Determinismus-Paare

Zeit und IDs sind injizierbar. `Clock.now()`, `IdGenerator.next(): Uuid`.

| Einsatz | Clock | IdGenerator |
|---|---|---|
| **Produktion** | `SystemClock` | `UuidV7IdGenerator` (echtes v7) |
| **Tests / Konformität** | `FixedClock` | `DeterministicIdGenerator` (Uhr + Zähler, kein Zufall) |

```php
$clock = FixedClock::at('2026-06-18T10:00:00Z');
$ids   = new DeterministicIdGenerator($clock);
$clock->advanceMilliseconds(5);
```
Tests **nie** gegen `now()`/Zufall schreiben.

### CanonicalJson

Kanonisches JSON nach **RFC 8785 (JCS)** — Grundlage aller Hashes/Vergleiche.
`CanonicalJson::encode($value)` (PHP) bzw. `canonicalJson(value)` (Node).
Schlüsselsortierung nach UTF-16-Code-Units; **Floats werden abgelehnt**;
Ganzzahlen nur `|x| ≤ 2^53−1`. Leeres PHP-Array = leere Liste `[]`; für `{}`
`stdClass` verwenden.

---

## 9. Fehlerkatalog

Fachliche Fehler werden als `DomainError` geworfen (PHP: `Summae\Core\DomainError`,
Node: gleiches Konzept/gleiche Codes). Drei Felder:

| Feld | Typ | Bedeutung |
|---|---|---|
| `errorCode` | string | stabiler `E_*`-Code — **Vertragsteil: gleicher Verstoß → gleicher Code in allen Implementierungen** |
| `message` | string | freie Beschreibung (Default = `errorCode`) |
| `details` | object | beteiligte IDs/Werte |

```php
try { $ops->execute('post', $input); }
catch (\Summae\Core\DomainError $e) { $e->errorCode; $e->details; $e->getMessage(); }
```
```ts
try { ops.execute('post', input); }
catch (e) { if (e instanceof DomainError) { e.errorCode; e.details; e.message; } }
```

**Buchung / Journal:** `E_ENTRY_TOO_FEW_LINES`, `E_ENTRY_INVALID_AMOUNT`,
`E_ENTRY_UNBALANCED`, `E_ENTRY_NO_VOUCHER`, `E_ENTRY_UNKNOWN`,
`E_ENTRY_FINALIZED`, `E_ENTRY_ALREADY_REVERSED`, `E_VOUCHER_UNKNOWN`.

**Konto / Dimensionen:** `E_ACCOUNT_UNKNOWN`, `E_ACCOUNT_NUMBER_TAKEN`,
`E_ACCOUNT_LOCKED`, `E_COA_FORMAT_INVALID`, `E_DIMENSION_INVALID`.

**Periode / Geschäftsjahr:** `E_PERIOD_UNKNOWN`, `E_PERIOD_CLOSED`,
`E_PERIOD_OUT_OF_ORDER`, `E_FISCALYEAR_CLOSED`, `E_FISCALYEAR_OVERLAP`,
`E_FISCALYEAR_UNFINALIZED_ENTRIES`.

**Steuer:** `E_TAXCODE_UNKNOWN`, `E_TAXCODE_NO_VALID_VERSION`,
`E_PROFILE_RETROACTIVE_CONFLICT`.

**Offene Posten:** `E_OPENITEM_UNKNOWN`, `E_SETTLEMENT_EXCEEDS_ITEM`,
`E_SETTLEMENT_DIFFERENCE_INVALID`.

**Anlagen:** `E_ASSET_UNKNOWN`, `E_ASSET_DISPOSED`.

**Costing (KLR):** `E_COSTING_RUN_UNKNOWN`, `E_COSTING_RUN_RELEASED`,
`E_COSTING_CYCLE`.

**Partner:** `E_PARTNER_UNKNOWN`.

**Mapping / Profil:** `E_MAPPING_OVERLAP`, `E_PROFILE_UNKNOWN`.

**EÜR:** `E_CASHBASIS_DEVIATING_FISCAL_YEAR`.

**Sonstige:** `E_NOT_IMPLEMENTED` (Operation/Projektion im Dispatcher nicht
verdrahtet).

Die **CLI** bildet denselben Katalog auf Exit-Codes ab: bei Fehlern gibt sie
`{"error": "E_…", "message": …, "details": …}` aus und beendet mit einem
Exit-Code ≥ 10.

> ⚠ Wert-/Format-Validierung der Value Objects (`InvalidValue`,
> `CurrencyMismatch`) ist **kein** `DomainError` und nicht Teil dieses Katalogs.

---

## 10. Determinismus & Datenformat

Gleiche Eingabe → byte-identisches Ergebnis, über Sprachen hinweg. Das macht
Ergebnisse reproduzierbar, testbar und zwischen Implementierungen austauschbar.

- **Uhr & IDs injizierbar** — Produktion: `SystemClock` + `UuidV7IdGenerator`;
  Tests: `FixedClock` + `DeterministicIdGenerator` (siehe § 8).
- **Buchungsdatum ist zonenlos** (`CalendarDate`, kein Zeitstempel mit UTC-Shift).
- **Sortierung** nach Unicode-Codepoints, **JSON** kanonisch (RFC 8785).
- **Geld nie als Float** — `Money`, half-up away-from-zero, `allocate`
  largest-remainder.
- **Austausch zwischen Implementierungen** läuft über das JSON-Datenformat
  (`journalExport` / Import), nicht über zwei lebende Engines auf derselben
  Live-DB. Eine andere Implementierung darf dieselbe Datenbank **lesen**;
  gleichzeitiges Schreiben durch zwei Engines auf dasselbe Journal ist bewusst
  zu vermeiden.

---

## 11. Weiterführend

- **Kompatibilitätsvertrag:** `testsuite/` (Fixtures + Schema) — die normative
  Quelle, gegen die jede Implementierung byte-identisch geprüft wird.
- **PHP-Entwickler-Doku** (Architektur, Workflow, Konformität):
  [implementations/php/docs/](../../implementations/php/docs/README.md).
- **Node-Entwickler-Doku:**
  [implementations/node/README.md](../../implementations/node/README.md).

Dieses Handbuch ist die maßgebliche Nutzer-Dokumentation; die Paket-READMEs sind
nur Einstiegszeiger.
