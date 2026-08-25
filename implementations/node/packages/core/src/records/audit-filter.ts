import type { AuditCriteria } from '../port.js';
import type { AuditRecord } from './audit-record.js';

/**
 * The criteria of `AuditTrail.find`, applied in memory (SPEC-018).
 *
 * One place, because the rule has to be the same wherever it runs: the in-memory adapter filters
 * with this, a database adapter filters with SQL, and the two must not answer differently. An
 * adapter suite in each language drives the same criteria through both and compares.
 *
 * The one asymmetry worth naming: a store can decline to *read* a row, and this cannot. That is the
 * whole point of the port method and the reason this is a fallback rather than the implementation.
 */
export function applyAuditCriteria(
  records: readonly AuditRecord[],
  criteria: AuditCriteria,
): { records: AuditRecord[]; count: number } {
  const matching = records.filter((record) => matches(record, criteria));
  const offset = Math.max(0, criteria.offset ?? 0);
  const limit = criteria.limit ?? null;

  // An absent limit means "everything from the offset on" — the same rule `journal` publishes.
  const page = limit === null || limit < 0 ? matching.slice(offset) : matching.slice(offset, offset + limit);

  return { records: page, count: matching.length };
}

function matches(record: AuditRecord, criteria: AuditCriteria): boolean {
  if (typeof criteria.objectType === 'string' && record.objectType !== criteria.objectType) return false;
  if (typeof criteria.action === 'string' && record.action !== criteria.action) return false;
  if (typeof criteria.actor === 'string' && record.actor !== criteria.actor) return false;
  if (typeof criteria.objectId === 'string' && record.objectId.value !== criteria.objectId) return false;
  if (Array.isArray(criteria.objectIds) && !criteria.objectIds.includes(record.objectId.value)) return false;

  const date = record.at.slice(0, 10);
  if (typeof criteria.from === 'string' && date < criteria.from) return false;

  return !(typeof criteria.to === 'string' && date > criteria.to);
}
