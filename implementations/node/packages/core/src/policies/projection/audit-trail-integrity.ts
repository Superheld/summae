import type { AuditTrail } from '../../port.js';
import { hashOf } from '../../records/audit-record.js';

/**
 * Walks the audit trail's hash chain and says whether it still holds (format 0.8).
 *
 * **Why this projection is the feature, and the two hash fields are only its data.** Until now the
 * trail was append-only *because no code path updates or deletes it* — a property of the procedure,
 * not of the data. An auditor could check the code, or trust the deployment, and nothing else;
 * `docs/gobd-conformance.md` §13 says plainly that a direct `UPDATE` against a `summae_*` table
 * leaves no trace. It now leaves one, and this is where it becomes visible. Storing hashes and never
 * offering a way to check them would be decoration.
 *
 * **Four states per record, and keeping them apart is the whole difficulty:**
 *
 * - **chained** — carries both hashes, its own recomputes, and its link matches its predecessor.
 * - **unchained** — written before format 0.8, so it has no hash. Reported as its own number and
 *   never as a break: a library that cried tampering over its own upgrade would be useless. They
 *   can only sit at the *front*; an unchained record appearing after a chained one is an insertion
 *   and is reported as a break.
 * - **redacted** — erased under a privacy right (F-CORE-040). The shell keeps both hashes, so the
 *   link still resolves; its content cannot be recomputed, because there is none. Counted, not
 *   verified, and the count is published so nobody reads the difference as a silent pass.
 * - **broken** — everything else, with the reason named.
 *
 * **What it cannot do**, stated because a guarantee's edge is part of it:
 *
 * - Records removed from the **end** leave nothing behind to notice. Every chain has this hole. The
 *   answer is the published `head`: keep it somewhere summae cannot reach and compare.
 * - Two concurrent appends can read the same head and both link to it. That is a *fork*, and it is
 *   reported as a break — truthfully, because from the data alone a fork and a removal look the
 *   same. Serialising appends is the embedding's to arrange, like every other write.
 * - It proves the trail's own integrity, not the books'. A chain over the postings would need
 *   `previousEntryHash`, which the data format reserves and forbids writers to populate in v0.x
 *   (SPEC-022) — a chain every conforming reader is told to ignore would be evidence for nobody.
 */
export class AuditTrailIntegrityProjection {
  constructor(private readonly audit: AuditTrail) {}

  run(): Record<string, unknown> {
    const records = this.audit.all();
    const breaks: Array<Record<string, unknown>> = [];
    let chained = 0;
    let unchained = 0;
    let redacted = 0;
    let previousHash: string | null = null;
    let seenChained = false;

    for (const record of records) {
      if (record.recordHash === null) {
        unchained += 1;
        if (seenChained) {
          breaks.push({
            recordId: record.id.value,
            at: record.at,
            reason: 'unchainedAfterChained',
            detail: 'a record without a hash follows chained ones, which is where an insertion shows',
          });
        }
        continue;
      }

      seenChained = true;
      if (record.isRedacted()) {
        redacted += 1;
      } else {
        chained += 1;
        const recomputed = hashOf(record);
        if (recomputed !== record.recordHash) {
          breaks.push({
            recordId: record.id.value,
            at: record.at,
            reason: 'contentMismatch',
            detail: 'the record no longer hashes to the value it carries',
          });
        }
      }

      if (record.previousRecordHash !== previousHash) {
        breaks.push({
          recordId: record.id.value,
          at: record.at,
          reason: 'linkMismatch',
          detail: 'the link does not name the preceding record — one was changed, removed or inserted',
        });
      }
      previousHash = record.recordHash;
    }

    return {
      records: records.length,
      chained,
      unchained,
      redacted,
      // The tip of the chain. Keep it outside summae and compare later: it is the only thing that
      // notices records dropped from the end.
      head: previousHash,
      intact: breaks.length === 0,
      breaks,
    };
  }
}
