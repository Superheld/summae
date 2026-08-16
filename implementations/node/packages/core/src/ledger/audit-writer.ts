import { AuditRecord, type AuditChanges } from '../records/audit-record.js';
import type { AuditTrail } from '../port.js';
import type { Clock } from '../substrate/clock.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import type { Uuid } from '../substrate/uuid.js';

/**
 * Writes the audit trail for the ledger services.
 *
 * Extracted because `Ledger`, `SettlementService` and `ChartAdminService` all need the same three
 * things — who acted, what time it is, and the record itself — and sharing them through a base
 * class would tie services together that otherwise have nothing in common.
 */
export class AuditWriter {
  constructor(
    private readonly audit: AuditTrail,
    private readonly clock: Clock,
    private readonly ids: IdGenerator,
  ) {}

  /** The actor of an operation; absent or empty means the system itself. */
  actorOf(input: Record<string, unknown>): string {
    const actor = typeof input.actor === 'string' ? input.actor : null;
    return actor !== null && actor !== '' ? actor : 'system';
  }

  now(): string {
    return this.clock.now().toISOString();
  }

  record(
    actor: string,
    objectType: string,
    objectId: Uuid,
    action: string,
    changes: AuditChanges = {},
  ): void {
    this.audit.append(new AuditRecord(this.ids.next(), this.now(), actor, objectType, objectId, action, changes));
  }
}
