import type { Uuid } from '../shared/uuid.js';

export type AuditChanges = Record<string, { from: unknown; to: unknown }>;

/**
 * Audit-Eintrag (datenformat.md v0.3 `auditLog.jsonl`): flacher Vorher/Nachher-
 * Diff nur der geänderten Felder.
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
      // Leerer Diff → {} (nicht []), damit das Format stabil bleibt.
      changes: this.changes,
    };
  }
}
