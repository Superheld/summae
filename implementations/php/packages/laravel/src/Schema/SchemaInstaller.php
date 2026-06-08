<?php

declare(strict_types=1);

namespace Summae\Laravel\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tabellenlayout des Adapters — eine Quelle für Migration und Tests.
 *
 * Journal append-only: Buchungszeilen, Perioden, Settlements und
 * AfA-Lebensläufe liegen als JSON-Dokumente am Aggregat (die Published
 * Language ist JSON; Projektionen rechnet der Kern, nie die Datenbank).
 */
final class SchemaInstaller
{
    public const string PREFIX = 'summae_';

    public static function create(Builder $schema): void
    {
        $schema->create(self::PREFIX . 'accounts', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('number', 64);
            $table->string('name');
            $table->string('type', 16);
            $table->string('subtype', 32)->nullable();
            $table->string('status', 16)->default('active');
            $table->unique(['tenant_id', 'number']);
        });

        $schema->create(self::PREFIX . 'fiscal_years', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->integer('year');
            $table->date('start');
            $table->date('end');
            $table->string('status', 16)->default('open');
            $table->json('periods');
            $table->unique(['tenant_id', 'year']);
        });

        $schema->create(self::PREFIX . 'vouchers', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->json('payload');
        });

        $schema->create(self::PREFIX . 'journal_entries', static function (Blueprint $table): void {
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

        $schema->create(self::PREFIX . 'open_items', static function (Blueprint $table): void {
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

        $schema->create(self::PREFIX . 'partners', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->json('payload');
        });

        $schema->create(self::PREFIX . 'assets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->json('payload');
            $table->json('state');
        });

        $schema->create(self::PREFIX . 'audit_log', static function (Blueprint $table): void {
            $table->bigIncrements('seq');
            $table->uuid('id')->unique();
            $table->uuid('tenant_id')->index();
            $table->json('payload');
        });
    }

    public static function drop(Builder $schema): void
    {
        foreach ([
            'accounts', 'fiscal_years', 'vouchers', 'journal_entries',
            'open_items', 'partners', 'assets', 'audit_log',
        ] as $table) {
            $schema->dropIfExists(self::PREFIX . $table);
        }
    }
}
