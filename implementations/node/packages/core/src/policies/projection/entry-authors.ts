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
 * **It asks for the entries it needs, not for the trail** (SPEC-018). The map is built from a `find`
 * over the ids on the page, so a journal view of forty postings reads forty records rather than ten
 * years of history. It used to read `all()`, which moved the embedding's walk into the library
 * without making it smaller.
 */
export function entryAuthors(audit: AuditTrail, entryIds: readonly string[]): Map<string, string> {
  const byEntry = new Map<string, string>();
  if (entryIds.length === 0) return byEntry;

  const found = audit.find({ objectType: 'journalEntry', action: 'created', objectIds: entryIds });
  for (const record of found.records) byEntry.set(record.objectId.value, record.actor);

  return byEntry;
}
