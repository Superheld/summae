import type { AuditTrail } from '../../port.js';

/**
 * Who recorded which posting (F-CORE-037) — the one fact about an entry that lives only in the
 * audit trail.
 *
 * A journal entry carries no author. The actor of the operation that created it is written into the
 * audit record at that moment and nowhere else, so `journal` and `unfinalizedEntries` could report
 * everything about a posting except who made it. An application building **separation of duties**
 * ("nobody may finalize a batch containing their own postings") therefore read the entire audit
 * trail on every finalization and rebuilt the mapping itself — which is an embedding reconstructing
 * library state from a trail, the move this project has already named as a bug waiting for the
 * first change that does not pass through the screen that writes it.
 *
 * **The trail stays the single source.** The author is not copied onto the entry: that would be a
 * second place for the same fact, and the entry is append-only — an author written there could
 * never be corrected while the trail's record of who acted is what an audit actually asks for.
 *
 * **What this does not fix.** Building the map still reads the whole trail, because the audit port
 * answers `all()` and the store keeps `objectType`/`action` inside a JSON payload rather than in
 * columns a query could filter on. The walk moved from the embedding into the library, where it
 * belongs and where one map serves the whole projection; it did not become a database query. Making
 * it one needs indexed columns on `summae_audit_log`, and the idempotent installer can add tables
 * but not columns — recorded as the next step rather than pretended away.
 */
export function entryAuthors(audit: AuditTrail): Map<string, string> {
  const byEntry = new Map<string, string>();

  for (const record of audit.all()) {
    if (record.objectType !== 'journalEntry' || record.action !== 'created') continue;
    byEntry.set(record.objectId.value, record.actor);
  }

  return byEntry;
}
