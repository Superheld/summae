import { DatabaseTenantFactory, SyncDb, installSchema } from '@superheld/summae-knex';
import type { Subject, SubjectFactory } from '../subject.js';
import { CoreSubject } from './core-subject.js';

/**
 * Database subject: the same conformance suite against the Knex/SQLite adapter
 * (`@superheld/summae-knex`). Passes `CoreSubject` a tenant builder that
 * wires up DB-backed ports — a fresh in-memory SQLite with installed
 * `summae_*` schema per tenant build. Counterpart to PHP's
 * `DatabaseSubjectFactory`.
 */
export class DatabaseSubjectFactory implements SubjectFactory {
  create(): Subject {
    return new CoreSubject((name, baseCurrency, clock, ids, dimensions, taxCodes, taxProfile, mappings) => {
      const db = new SyncDb(':memory:');
      installSchema(db);
      return DatabaseTenantFactory.build(db, name, baseCurrency, clock, ids, {
        dimensions,
        taxCodes,
        taxProfile,
        mappings,
      });
    });
  }
}
