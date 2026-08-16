<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Summae\Core\Substrate\Currency;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\SummaeServiceProvider;

/**
 * The service provider is the whole Laravel entry point: `composer require
 * superheld/summae-laravel`, auto-discovery, `php artisan migrate`, then
 * `app(DatabaseTenantFactory::class)`. Everything the rest of the adapter suite tests sits
 * *behind* it — those tests build their connection by hand and never touch the wiring, so the
 * one file a Laravel user cannot avoid was the one file with no test at all.
 *
 * What can go wrong here is silent, not loud: migrations that are never registered (a user runs
 * `artisan migrate` and gets no `summae_*` tables), a factory bound to the default connection
 * while `summae.connection` names another (bookkeeping written into the wrong database), or a
 * config that stops being publishable. None of that fails anywhere else in the suite.
 *
 * Testbench rather than a hand-built container: `mergeConfigFrom`, `loadMigrationsFrom` and
 * `publishes` all reach into a real application, and faking one would test the fake.
 */
final class ServiceProviderTest extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [SummaeServiceProvider::class];
    }

    /**
     * `$this->app` is `Application|null` to PHPStan and `artisan()` returns `PendingCommand|int`.
     * Narrowing here rather than silencing it in `phpstan.neon`: the analysis stays at level max
     * for this file, and a null application fails as an assertion instead of a type error.
     */
    private function application(): Application
    {
        $app = $this->app;
        self::assertInstanceOf(Application::class, $app);

        return $app;
    }

    private function migrate(): void
    {
        self::assertSame(0, Artisan::call('migrate'));
    }

    public function testRegistersTheFactoryAsASingleton(): void
    {
        $first = $this->application()->make(DatabaseTenantFactory::class);
        $second = $this->application()->make(DatabaseTenantFactory::class);

        self::assertInstanceOf(DatabaseTenantFactory::class, $first);
        self::assertSame($first, $second, 'a second resolve must not open a second connection');
    }

    public function testMergesItsConfigSoTheKeyExistsWithoutPublishing(): void
    {
        // The default is null = "the app's default connection". The key must exist regardless,
        // otherwise `config('summae.connection')` in the provider would read null from a missing
        // config rather than from the documented default — the same value for the wrong reason.
        $config = $this->application()->make('config');
        self::assertInstanceOf(ConfigRepository::class, $config);
        self::assertTrue($config->has('summae.connection'));
    }

    public function testLoadsTheMigrationsSoArtisanMigrateCreatesTheTables(): void
    {
        $this->migrate();

        // There is no `summae_tenants` table — a tenant is a `tenant_id` column on every one of
        // these. Checked across three of the eight rather than one, so a migration that dies
        // halfway still shows up.
        self::assertTrue(Schema::hasTable('summae_accounts'), 'migrate must create the summae_* schema');
        self::assertTrue(Schema::hasTable('summae_journal_entries'));
        self::assertTrue(Schema::hasTable('summae_open_items'));
    }

    public function testBuildsAWorkingTenantThroughTheContainer(): void
    {
        $this->migrate();

        $factory = $this->application()->make(DatabaseTenantFactory::class);
        $tenant = $factory->build('Provider GmbH', Currency::of('EUR'));

        self::assertSame('Provider GmbH', $tenant->name);
    }

    public function testOffersTheConfigForPublishing(): void
    {
        $paths = SummaeServiceProvider::pathsToPublish(SummaeServiceProvider::class, 'summae-config');

        self::assertNotSame([], $paths, 'the summae-config tag must stay publishable');
        self::assertStringEndsWith('summae.php', (string) array_key_first($paths));
    }
}
