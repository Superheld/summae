import type { AuditTrail } from '../port.js';
import { CalendarDate } from '../shared/calendar-date.js';

/**
 * Änderungshistorie als Projektion (F-CORE-014). Reihenfolge =
 * Erfassungsreihenfolge des Audit-Trails.
 */
export class AuditLogProjection {
  constructor(private readonly audit: AuditTrail) {}

  compute(params: Record<string, unknown>): { records: Array<Record<string, unknown>> } {
    const from = typeof params.from === 'string' ? CalendarDate.of(params.from) : null;
    const to = typeof params.to === 'string' ? CalendarDate.of(params.to) : null;

    const records: Array<Record<string, unknown>> = [];
    for (const record of this.audit.all()) {
      const date = CalendarDate.of(record.at.slice(0, 10));
      if (from !== null && date.isBefore(from)) continue;
      if (to !== null && date.isAfter(to)) continue;
      records.push(record.toJSON());
    }
    return { records };
  }
}
