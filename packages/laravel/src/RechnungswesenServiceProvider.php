<?php

declare(strict_types=1);

namespace Rechnungswesen\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Laravel-Einstiegspunkt. Container-Bindings, Migrationen und Config
 * folgen in JOB-012 (Eloquent-Adapter).
 */
final class RechnungswesenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
