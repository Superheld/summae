import { describeAuditContract, inMemoryTenant } from './audit-cases.js';

/**
 * The audit-completeness contract against the in-memory ports (F-CORE-014, GoBD Rz. 107 ff.).
 *
 * Every case lives in `audit-cases.ts`, which the knex suite binds a second time against real
 * persistence. Two bindings of one enumeration, because the enumeration is the part that must not
 * drift and the tenant is the part that has to vary.
 */
describeAuditContract('in memory', inMemoryTenant);
