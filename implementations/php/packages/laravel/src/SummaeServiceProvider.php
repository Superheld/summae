<?php

declare(strict_types=1);

namespace Summae\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel-Einstiegspunkt: `composer require superheld/summae-laravel`
 * — der framework-freie Kern kommt als Abhängigkeit mit, der Nutzer
 * richtet genau ein Package ein.
 *
 * Bereitgestellt wird die DatabaseTenantFactory (Mandanten mit
 * DB-Persistenz) auf der konfigurierten Connection; Migrationen und
 * Config sind publizierbar.
 */
final class SummaeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/summae.php', 'summae');

        $this->app->singleton(DatabaseTenantFactory::class, static function (Application $app): DatabaseTenantFactory {
            /** @var \Illuminate\Database\DatabaseManager $db */
            $db = $app->make('db');
            /** @var \Illuminate\Contracts\Config\Repository $config */
            $config = $app->make('config');
            /** @var string|null $connectionName */
            $connectionName = $config->get('summae.connection');

            /** @var ConnectionInterface $connection */
            $connection = $db->connection($connectionName);

            return new DatabaseTenantFactory($connection);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/summae.php' => $this->app->configPath('summae.php'),
        ], 'summae-config');
    }
}
