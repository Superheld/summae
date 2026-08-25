import type { AuditRecord } from '../../records/audit-record.js';
import type { AuditTrail } from '../../port.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import { integerOr, integerOrNull } from './parameters.js';

/**
 * Change history as a projection (F-CORE-014, F-CORE-036; GoBD Rz. 107 ff.).
 *
 * Order = recording order of the audit trail, which is already its total order (the sequence in the
 * store, insertion order in memory). Paging needs a stable one, and inventing a tie-break where the
 * trail already has none would be a second answer to a question that has one.
 *
 * **Filters, because the auditor's question is about one thing.** Until 0.13.0 the only parameters
 * were `from`/`to`, so "who touched this account", "what happened to this posting" and "what did
 * this user do" were not askable: the caller had to fetch the whole trail and filter outside. That
 * is the wrong place twice over — it moves the fastest-growing table in the system across a
 * boundary to discard most of it, and it makes progressive and retrograde traceability a property
 * of the embedding rather than of the books.
 *
 * All filters combine with AND, and an absent one filters nothing. `count` is the number of records
 * matching the filters *before* paging, so a page header can say "51–100 of 3,204" without a second
 * call — the same contract `journal` publishes, and deliberately the same words.
 */
export class AuditLogProjection {
  constructor(private readonly audit: AuditTrail) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const from = typeof params.from === 'string' ? CalendarDate.of(params.from) : null;
    const to = typeof params.to === 'string' ? CalendarDate.of(params.to) : null;
    const objectType = typeof params.objectType === 'string' ? params.objectType : null;
    const objectId = typeof params.objectId === 'string' ? params.objectId : null;
    const actor = typeof params.actor === 'string' ? params.actor : null;
    const action = typeof params.action === 'string' ? params.action : null;
    const offset = Math.max(0, integerOr(params.offset, 0));
    const limit = integerOrNull(params.limit);

    const matching: Array<Record<string, unknown>> = [];
    for (const record of this.audit.all()) {
      if (!matches(record, from, to, objectType, objectId, actor, action)) continue;
      matching.push(record.toJSON());
    }

    // A limit that is absent means "everything from the offset on" — a projection that invented a
    // default page size would silently truncate a caller that never asked for pages.
    const page = limit === null || limit < 0 ? matching.slice(offset) : matching.slice(offset, offset + limit);

    return { count: matching.length, offset, limit, records: page };
  }
}

function matches(
  record: AuditRecord,
  from: CalendarDate | null,
  to: CalendarDate | null,
  objectType: string | null,
  objectId: string | null,
  actor: string | null,
  action: string | null,
): boolean {
  const date = CalendarDate.of(record.at.slice(0, 10));

  if (from !== null && date.isBefore(from)) return false;
  if (to !== null && date.isAfter(to)) return false;
  if (objectType !== null && record.objectType !== objectType) return false;
  if (objectId !== null && record.objectId.value !== objectId) return false;
  if (actor !== null && record.actor !== actor) return false;

  return !(action !== null && record.action !== action);
}
