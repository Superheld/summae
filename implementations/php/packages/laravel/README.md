# superheld/summae-laravel

Laravel-Integration von summae: ServiceProvider, Datenbank-Persistenz
(`summae_*`-Tabellen), Migrationen. Der framework-freie Kern
(`superheld/summae-core`) kommt automatisch als Abhängigkeit mit — du
installierst **ein** Package.

```bash
composer require superheld/summae-laravel
php artisan migrate
```

Der ServiceProvider wird per Package-Discovery automatisch registriert. Ohne
weitere Konfiguration nutzt das Package die Default-DB-Connection deiner App.

```php
use Summae\Core\Shared\Currency;
use Summae\Core\Composition\TenantOperations;
use Summae\Laravel\DatabaseTenantFactory;

$tenant = app(DatabaseTenantFactory::class)->build('Muster GmbH', Currency::of('EUR'));
$ops    = new TenantOperations($tenant);
```

**📖 Vollständige Dokumentation** — Konfiguration (eigene DB-Connection,
`SUMMAE_DB_CONNECTION`, Migration), Initialisierung, komplette API-Referenz,
Fehlerkatalog: **[summae-Handbuch](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.

Lizenz: MIT — siehe [LICENSE](https://github.com/Superheld/summae/blob/main/implementations/php/LICENSE).
