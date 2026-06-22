import type { Uuid } from '../substrate/uuid.js';

export type AuditChanges = Record<string, { from: unknown; to: unknown }>;

/**
 * Audit entry (datenformat.md v0.3 `auditLog.jsonl`): flat before/after
 * diff of only the changed fields.
 */
export class AuditRecord {
  constructor(
    readonly id: Uuid,
    readonly at: string,
    readonly actor: string,
    readonly objectType: string,
    readonly objectId: Uuid,
    readonly action: string,
    readonly changes: AuditChanges = {},
  ) {}

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      at: this.at,
      actor: this.actor,
      objectType: this.objectType,
      objectId: this.objectId.value,
      action: this.action,
      // Empty diff → {} (not []), so the format stays stable.
      changes: this.changes,
    };
  }
}
