# superheld/summae-core (PHP)

Framework-freier Rechnungswesen-Kern in PHP — die gesamte Buchführungslogik
(GoBD-Doppik, EÜR, USt-VA, Anlagen, KLR). Referenzimplementierung der
summae-Spezifikation; einzige Laufzeit-Abhängigkeit: `brick/math`.

Dieses Paket enthält **keine** Framework-Bindung. Für Laravel-Persistenz gibt es
`superheld/summae-laravel`, für ein Terminal-Werkzeug `superheld/summae-cli`.

## Installation

```bash
composer require superheld/summae-core
```

Voraussetzung: **PHP ≥ 8.3** (empfohlen mit `bcmath`- oder `gmp`-Extension für
schnelle Dezimalarithmetik — läuft auch ohne, dann langsamer).

## Öffentliche API

Einstieg ist der **Dispatcher** `TenantOperations` — Operationen (`execute`) und
Projektionen (`project`), Namen exakt nach Spezifikation:

```php
$ops = new TenantOperations($tenant);
$ops->execute('post', $input);              // Schreiboperationen
$ops->project('trialBalance', $params);     // lesende Projektionen
```

Operationen: `post`, `postVoucher`, `correct`, `finalize`, `reverse`, `settle`,
`closePeriod`/`reopenPeriod`/`closeFiscalYear`, `createAccount`/`createFiscalYear`,
`lockAccount`, `importChartOfAccounts`, `importMapping`, `expandTax`/`setTaxProfile`,
`acquireAsset`/`disposeAsset`/`runDepreciation`, `setAllocationScheme`/`runCosting`/
`releaseCosting`, `createPartner`/`updatePartner`, `createTenant`.

Projektionen: `trialBalance`, `accountSheet`, `auditLog`, `openItems`, `vatReturn`,
`incomeStatement`, `balanceSheet`, `cashBasisReport`, `assetRegister`,
`costAllocationSheet`, `ecSalesList`, `journalExport`, `datevExport`.

Daneben exportiert das Paket die Value Objects (`Money`, `Currency`,
`CalendarDate`, `AccountNumber`, `Uuid`) und Aggregate.

## Beispiel (In-Memory)

```php
use Summae\Core\Tenant;
use Summae\Core\Shared\{Currency, CalendarDate, FixedClock, DeterministicIdGenerator};
use Summae\Core\Composition\TenantOperations;

// Determinismus-Hooks injizierbar (Produktion: SystemClock + UuidV7IdGenerator).
$clock  = FixedClock::at('2026-06-07T12:00:00+02:00');
$tenant = Tenant::inMemory('Demo GmbH', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));
$ops    = new TenantOperations($tenant);

$ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
$ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank',    'type' => 'asset',     'subtype' => 'bank']);
$ops->execute('createAccount', ['number' => '8400', 'name' => 'Erlöse',  'type' => 'revenue']);
$ops->execute('createAccount', ['number' => '1776', 'name' => 'USt 19%', 'type' => 'liability', 'subtype' => 'tax_out']);

$susa = $ops->project('trialBalance', ['fiscalYear' => 2026, 'throughPeriod' => 12]);
```

Vollständige Anleitung (Initialisierung, Konfiguration, Operationen):
**→ [Handbuch](../../../../docs/handbuch/README.md)**.

## Prinzipien

- **Journal append-only; Salden sind Projektionen** — nie einen Saldo speichern.
- **Geld nie als Float** (`Money` auf `brick/math`, half-up away-from-zero,
  `allocate` largest-remainder).
- **Determinismus** (`Clock`/`IdGenerator` injizierbar; gleiche Eingabe →
  byte-identisches Ergebnis).

Normative Quelle ist die Konformitäts-Suite (`testsuite/` im Repo-Root); diese
PHP-Implementierung ist der Goldstandard für die übrigen Sprachen.

## Lizenz

MIT — siehe [LICENSE](../../LICENSE).
