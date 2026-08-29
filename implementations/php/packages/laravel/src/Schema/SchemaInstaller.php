<?php

declare(strict_types=1);

namespace Summae\Laravel\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Table layout of the adapter — one source for migration and tests.
 *
 * Journal append-only: posting lines, periods, settlements and
 * depreciation life cycles live as JSON documents on the aggregate (the
 * published language is JSON; projections are computed by the core, never the database).
 */
final class SchemaInstaller
{
    public const string PREFIX = 'summae_';

    /**
     * Installs what is missing, **per table** (SPEC-014).
     *
     * It used to create all of them unconditionally, exactly once, at workspace initialisation, and
     * nothing upgraded an existing database — so the first change that added a table (the costing
     * runs) had no path into a workspace that already existed except recreating it. Running this
     * again now creates only what is absent.
     *
     * What that covers and what it does not is worth stating plainly, because the honest limit is
     * the reason this shape was chosen over a migration runner: it covers **additive** changes — a
     * new table, and since 2026-08-28 a new **nullable column** on a table that already exists
     * (`ensureColumn`) — and nothing else. A column that changes its type or a table that has to be
     * rewritten still needs a real migration, which neither language has. Until one exists, a change
     * of that kind means recreating the workspace, and saying so out loud is better than a runner
     * that only looks like one.
     *
     * The column half used to read "by hand", and the first change that needed it showed why that
     * was not good enough: an existing workspace kept the old table and failed on the next insert
     * with an unknown column, which is a loud failure but one nobody could fix from inside summae.
     */
    public static function create(Builder $schema): void
    {
        self::ensure($schema, 'accounts', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('number', 64);
            $table->string('name');
            $table->string('type', 16);
            $table->string('subtype', 32)->nullable();
            $table->string('status', 16)->default('active');
            // F-CORE-045: the window in which the account may be POSTED to. Both nullable, both
            // unbounded by default — every account that existed before the window did keeps
            // behaving exactly as it did.
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unique(['tenant_id', 'number']);
        });

        self::ensure($schema, 'fiscal_years', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->integer('year');
            $table->date('start');
            $table->date('end');
            $table->string('status', 16)->default('open');
            $table->json('periods');
            $table->unique(['tenant_id', 'year']);
        });

        self::ensure($schema, 'vouchers', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->json('payload');
        });

        self::ensure($schema, 'journal_entries', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->integer('fiscal_year');
            $table->integer('sequence_number');
            $table->integer('period');
            $table->string('status', 16);
            $table->date('entry_date');
            $table->date('voucher_date')->nullable();
            $table->string('recorded_at', 40);
            $table->uuid('voucher_id');
            $table->text('text');
            $table->json('lines');
            $table->uuid('reverses')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->unique(['tenant_id', 'fiscal_year', 'sequence_number']);
        });

        self::ensure($schema, 'open_items', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('kind', 16);
            $table->uuid('origin_entry_id')->index();
            $table->integer('origin_line_index');
            $table->string('amount', 32);
            $table->string('currency', 3);
            $table->uuid('voucher_id');
            $table->date('opened_at');
            $table->uuid('partner_id')->nullable();
            $table->json('settlements');
        });

        self::ensure($schema, 'partners', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->json('payload');
        });

        self::ensure($schema, 'assets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->json('payload');
            $table->json('state');
        });

        self::ensure($schema, 'audit_log', static function (Blueprint $table): void {
            $table->bigIncrements('seq');
            $table->uuid('id')->unique();
            $table->uuid('tenant_id')->index();
            $table->json('payload');
        });

        self::ensure($schema, 'costing_runs', static function (Blueprint $table): void {
            // Period, version and status are columns rather than payload fields because they are
            // what a run is *found* by — the next version of a period, and the released runs an
            // evaluation may read.
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->integer('fiscal_year');
            $table->integer('period');
            $table->integer('version');
            $table->string('status', 16);
            $table->json('payload');
            $table->unique(['tenant_id', 'fiscal_year', 'period', 'version']);
        });

        self::ensure($schema, 'inventory_valuations', static function (Blueprint $table): void {
            // Same shape and the same reasoning as costing_runs: period and version are columns
            // because they are what a valuation is FOUND by. No `status` — a valuation has no draft
            // state; repeating one is the next version, and its posting is the difference.
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->integer('fiscal_year');
            $table->integer('period');
            $table->integer('version');
            $table->json('payload');
            $table->unique(['tenant_id', 'fiscal_year', 'period', 'version']);
        });

        self::ensure($schema, 'tenants', static function (Blueprint $table): void {
            // The tenant itself (SPEC-015) — the one table that is not made of bookkeeping records.
            //
            // `tenant_id` is a column on every other table and used to point at nothing: a tenant
            // existed only in whatever the embedding remembered, so a wrong id opened an empty
            // ledger that was indistinguishable from a new one. It also carries the configuration —
            // tax profile, dimension master data, allocation scheme, imported mappings — which five
            // operations changed and no store kept.
            //
            // Name and currency are columns because they are what a tenant is *listed* by; the
            // configuration is a JSON document because it is only ever read whole.
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('base_currency', 3);
            $table->string('pack_id')->nullable();
            $table->string('pack_version')->nullable();
            $table->json('config');
        });

        // Columns added to a table that already exists (F-CORE-045). `ensure` above only ever
        // creates a MISSING table, so a workspace opened after an upgrade kept the old columns and
        // the first `add()` failed on an unknown one. The docblock called that "by hand", which is
        // a support burden for a change the installer can perfectly well make itself — as long as
        // it stays what it says: **nullable columns only**, never a type change and never a
        // rewrite. Those still need a real migration, which neither language has.
        self::ensureColumn($schema, 'accounts', 'valid_from', static function (Blueprint $table): void {
            $table->date('valid_from')->nullable();
        });
        self::ensureColumn($schema, 'accounts', 'valid_to', static function (Blueprint $table): void {
            $table->date('valid_to')->nullable();
        });
    }

    public static function drop(Builder $schema): void
    {
        foreach ([
            'accounts', 'fiscal_years', 'vouchers', 'journal_entries',
            'open_items', 'partners', 'assets', 'costing_runs', 'inventory_valuations', 'audit_log', 'tenants',
        ] as $table) {
            $schema->dropIfExists(self::PREFIX . $table);
        }
    }

    /**
     * Creates one table if it is not there yet.
     *
     * @param \Closure(Blueprint): void $definition
     */
    private static function ensure(Builder $schema, string $table, \Closure $definition): void
    {
        $name = self::PREFIX . $table;
        if ($schema->hasTable($name)) {
            return;
        }

        $schema->create($name, $definition);
    }

    /**
     * Adds one **nullable** column if the table is there and the column is not.
     *
     * Deliberately not a general migration: it can only add, it is checked per column, and it
     * silently does nothing when the table itself is missing — `ensure` will have created that one
     * with the column already in it.
     *
     * @param \Closure(Blueprint): void $definition
     */
    private static function ensureColumn(Builder $schema, string $table, string $column, \Closure $definition): void
    {
        $name = self::PREFIX . $table;
        if (!$schema->hasTable($name) || $schema->hasColumn($name, $column)) {
            return;
        }

        $schema->table($name, $definition);
    }
}
