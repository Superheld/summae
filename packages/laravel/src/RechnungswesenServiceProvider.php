<?php

declare(strict_types=1);

namespace Rechnungswesen\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel-Einstiegspunkt: `composer require superheld/rechnungswesen-laravel`
 * — der framework-freie Kern kommt als Abhängigkeit mit, der Nutzer
 * richtet genau ein Package ein.
 *
 * Bereitgestellt wird die EloquentTenantFactory (Mandanten mit
 * DB-Persistenz) auf der konfigurierten Connection; Migrationen und
 * Config sind publizierbar.
 */
final class RechnungswesenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rechnungswesen.php', 'rechnungswesen');

        $this->app->singleton(EloquentTenantFactory::class, static function (Application $app): EloquentTenantFactory {
            /** @var \Illuminate\Database\DatabaseManager $db */
            $db = $app->make('db');
            /** @var \Illuminate\Contracts\Config\Repository $config */
            $config = $app->make('config');
            /** @var string|null $connectionName */
            $connectionName = $config->get('rechnungswesen.connection');

            /** @var ConnectionInterface $connection */
            $connection = $db->connection($connectionName);

            return new EloquentTenantFactory($connection);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/rechnungswesen.php' => $this->app->configPath('rechnungswesen.php'),
        ], 'rechnungswesen-config');
    }
}
