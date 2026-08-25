import { Currency, DeterministicIdGenerator, type FixedClock, type Tenant } from '@superheld/summae-core';
// The case library lives in the core suite and is bound twice on purpose — see its header. Reaching
// across the package boundary for a TEST helper is deliberate: the alternative is a second copy of
// forty recipes, and a copy that nothing compares is the thing this whole file exists to prevent.
import { describeAuditContract } from '../../core/test/audit-cases.js';
import { DatabaseTenantFactory } from '../src/database-tenant-factory.js';
import { installSchema } from '../src/schema-installer.js';
import { SyncDb } from '../src/sync-db.js';

/**
 * The audit-completeness contract, run against **real persistence** (F-CORE-014, GoBD Rz. 107 ff.).
 *
 * Every case comes from `audit-cases.ts` by binding — the enumeration of operations, the recipes,
 * the published-event guard and the before/after guard. Only the tenant differs: this one is built
 * by `DatabaseTenantFactory` and writes through the knex audit trail into `summae_audit_log`.
 *
 * **Why a second binding rather than trust.** The completeness check existed only for
 * `Tenant.inMemory`, which is the one construction summae does not ship. It could therefore not see
 * the defect class that actually occurred: the factory takes the audit writer as an OPTIONAL
 * argument and used to leave it off for three services, so the tax profile, the asset events and
 * the costing runs wrote no record at all behind a database while every in-memory test stayed green
 * (fixed in 0.12.0, unguarded until now). Wiring a new service without its writer fails here now.
 *
 * It also covers the round trip the core suite cannot: every record asserted here has been through
 * a JSON column and come back — `changes`, `actor` and the canonical timestamp included.
 *
 * PHP twin: `packages/laravel/tests/AuditTrailPersistedTest.php`.
 */

/**
 * A fresh database per tenant, not per test.
 *
 * The case library calls this once per recipe and means "a tenant that has nothing yet"; two of its
 * cases run every recipe in turn. On one shared database those tenants would also share a tenant id
 * — the deterministic generator restarts with the clock and hands out the same first uuid — so the
 * second recipe would trip over the first one's chart of accounts. A database of its own is the
 * smallest thing that makes "fresh" mean the same here as it does in memory.
 */
function persistedTenant(clock: FixedClock): Tenant {
  const db = new SyncDb(':memory:');
  installSchema(db);

  return DatabaseTenantFactory.build(db, clock, new DeterministicIdGenerator(clock), {
    name: 'Audit GmbH',
    baseCurrency: Currency.of('EUR'),
  });
}

describeAuditContract('persisted (knex)', persistedTenant);
