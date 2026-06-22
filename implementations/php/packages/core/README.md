# superheld/summae-core (PHP)

Framework-free accounting core: GoBD double-entry, cash-basis accounting (EÜR), VAT,
fixed assets, cost accounting. Reference implementation of summae; only runtime
dependency: `brick/math`. No framework binding (Laravel adapter: `superheld/summae-laravel`).

```bash
composer require superheld/summae-core
```

```php
use Summae\Core\Tenant;
use Summae\Core\Substrate\Currency;
use Summae\Core\Composition\TenantOperations;

$ops = new TenantOperations(Tenant::inMemory('Demo GmbH', Currency::of('EUR')));
$ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
$susa = $ops->project('trialBalance', ['fiscalYear' => 2026, 'throughPeriod' => 12]);
```

**📖 Full documentation** — installation, initialization, configuration,
complete API reference (all operations & projections), value objects,
error catalog: **[summae handbook](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.

License: MIT — see [LICENSE](https://github.com/Superheld/summae/blob/main/implementations/php/LICENSE).
