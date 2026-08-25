<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;
use Summae\Core\Tests\Composition\AuditTrailContractTest;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * The audit-completeness contract, run against **real persistence** (F-CORE-014, GoBD Rz. 107 ff.).
 *
 * Every case comes from the core test by inheritance — the enumeration of operations, the recipes,
 * the published-event guard and the before/after guard. Only the tenant differs: this one is built
 * by `DatabaseTenantFactory` and writes through `DatabaseAuditTrail` into `summae_audit_log`.
 *
 * **Why a second binding rather than trust.** The completeness check existed only for
 * `Tenant::inMemory`, which is the one construction summae does not ship. It could therefore not
 * see the defect class that actually occurred: `DatabaseTenantFactory` takes the `AuditWriter` as
 * an OPTIONAL argument and used to leave it off for three services, so the tax profile, the asset
 * events and the costing runs wrote no record at all behind a database while every in-memory test
 * stayed green (fixed in 0.12.0, unguarded until now). An optional dependency makes that silent:
 * nothing fails to compile, nothing warns, and the trail is merely thinner in the setup that
 * counts. Wiring a new service without its writer fails here now, in both languages.
 *
 * It also covers the round trip the core test cannot: every record asserted here has been through a
 * JSON column and come back — `changes`, `actor` and the canonical timestamp included.
 *
 * The twin is `packages/knex/test/audit-trail-contract.test.ts`.
 */
final class AuditTrailPersistedTest extends AuditTrailContractTest
{
    /**
     * A fresh database per tenant, not per test.
     *
     * The base class calls this once per recipe and means "a tenant that has nothing yet"; two of
     * its cases run every recipe in turn. On one shared connection those tenants would also share
     * a tenant id — the deterministic generator restarts with the clock and hands out the same
     * first uuid — so the second recipe would trip over the first one's chart of accounts. A
     * connection of its own is the smallest thing that makes "fresh" mean the same here as it does
     * in memory.
     *
     * The connection is not kept: nothing outside the returned tenant reads it, and the repositories
     * hold their own reference.
     */
    protected function buildTenant(FixedClock $clock): Tenant
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);

        $connection = $capsule->getConnection();
        SchemaInstaller::create($connection->getSchemaBuilder());

        return (new DatabaseTenantFactory($connection))->build(
            'Audit GmbH',
            Currency::of('EUR'),
            $clock,
            new DeterministicIdGenerator($clock),
        );
    }
}
