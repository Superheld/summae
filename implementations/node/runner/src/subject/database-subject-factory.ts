import { DatabaseTenantFactory, SyncDb, installSchema } from '@superheld/summae-knex';
import type { Subject, SubjectFactory } from '../subject.js';
import { CoreSubject } from './core-subject.js';

/**
 * Database-Subject: dieselbe Konformitätssuite gegen den Knex/SQLite-Adapter
 * (`@superheld/summae-knex`). Reicht `CoreSubject` einen Tenant-Builder herein,
 * der DB-gestützte Ports verdrahtet — je Mandanten-Aufbau eine frische
 * In-Memory-SQLite mit installiertem `summae_*`-Schema. Pendant zu PHPs
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
