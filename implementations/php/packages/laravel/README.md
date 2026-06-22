# superheld/summae-laravel

Laravel integration of summae: ServiceProvider, database persistence
(`summae_*` tables), migrations. The framework-free core
(`superheld/summae-core`) comes along automatically as a dependency — you
install **one** package.

```bash
composer require superheld/summae-laravel
php artisan migrate
```

The ServiceProvider is registered automatically via package discovery. Without
further configuration the package uses your app's default DB connection.

```php
use Summae\Core\Substrate\Currency;
use Summae\Core\Composition\TenantOperations;
use Summae\Laravel\DatabaseTenantFactory;

$tenant = app(DatabaseTenantFactory::class)->build('Example Ltd', Currency::of('EUR'));
$ops    = new TenantOperations($tenant);
```

**📖 Full documentation** — configuration (custom DB connection,
`SUMMAE_DB_CONNECTION`, migration), initialization, complete API reference,
error catalog: **[summae handbook](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.

License: MIT — see [LICENSE](https://github.com/Superheld/summae/blob/main/implementations/php/LICENSE).
