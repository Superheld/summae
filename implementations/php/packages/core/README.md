# superheld/summae-core (PHP)

Framework-freier Rechnungswesen-Kern: GoBD-Doppik, EÜR, Umsatzsteuer, Anlagen,
KLR. Referenzimplementierung von summae; einzige Laufzeit-Abhängigkeit:
`brick/math`. Keine Framework-Bindung (Laravel-Adapter: `superheld/summae-laravel`).

```bash
composer require superheld/summae-core
```

```php
use Summae\Core\Tenant;
use Summae\Core\Shared\Currency;
use Summae\Core\Composition\TenantOperations;

$ops = new TenantOperations(Tenant::inMemory('Demo GmbH', Currency::of('EUR')));
$ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
$susa = $ops->project('trialBalance', ['fiscalYear' => 2026, 'throughPeriod' => 12]);
```

**📖 Vollständige Dokumentation** — Installation, Initialisierung, Konfiguration,
komplette API-Referenz (alle Operationen & Projektionen), Value Objects,
Fehlerkatalog: **[summae-Handbuch](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.

Lizenz: MIT — siehe [LICENSE](https://github.com/Superheld/summae/blob/main/implementations/php/LICENSE).
