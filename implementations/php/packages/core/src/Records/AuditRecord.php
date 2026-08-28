<?php

declare(strict_types=1);

namespace Summae\Core\Records;

use Summae\Core\Substrate\CanonicalJson;
use Summae\Core\Substrate\Timestamp;
use Summae\Core\Substrate\Uuid;

/**
 * Audit entry (datenformat.md `auditLog.jsonl`): flat before/after diff of only the changed fields,
 * plus — since format 0.8 — its link in the trail's hash chain.
 *
 * **Why the chain is here and not on the journal entry.** docs/gobd-conformance.md §14 item 5c asked
 * for tamper evidence on the *trail*: today it is append-only because no code path updates or
 * deletes it, which is a property of the procedure rather than of the data — an auditor has to trust
 * the deployment instead of checking the file. The obvious-looking alternative, a chain over the
 * postings, is **not available**: datenformat.md reserves `previousEntryHash` on the posting and
 * says writers must not populate it in v0.x while readers must ignore it. A chain every conforming
 * reader is instructed to ignore is evidence for nobody (SPEC-022). The trail's records carry no
 * such reservation, and the trail is what the row asked about.
 *
 * **`recordHash` covers the record including its `previousRecordHash`**, so changing any earlier
 * record invalidates every later link. Removing a record breaks the link at its successor. What a
 * chain cannot do is notice records removed from the *end* — which is why the head is published.
 */
final readonly class AuditRecord implements \JsonSerializable
{
    /**
     * The value every field of a redacted record carries. A reserved objectType, so a redacted
     * record cannot be confused with a record about a real object and no existing filter matches it.
     */
    public const string REDACTED = 'redacted';

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     * @param string|null $previousRecordHash the predecessor's recordHash; null for the first record
     *                                        and for anything written before 0.8
     * @param string|null $recordHash this record's own hash; null only before 0.8
     */
    public function __construct(
        public Uuid $id,
        public \DateTimeImmutable $at,
        public string $actor,
        public string $objectType,
        public Uuid $objectId,
        public string $action,
        public array $changes = [],
        public ?string $previousRecordHash = null,
        public ?string $recordHash = null,
    ) {
    }

    /**
     * The same record, linked behind $previousHash and carrying its own hash.
     *
     * The trail computes this at append, because the head is the one thing only the store knows.
     */
    public function chainedTo(?string $previousHash): self
    {
        $linked = new self(
            $this->id,
            $this->at,
            $this->actor,
            $this->objectType,
            $this->objectId,
            $this->action,
            $this->changes,
            $previousHash,
            null,
        );

        return new self(
            $this->id,
            $this->at,
            $this->actor,
            $this->objectType,
            $this->objectId,
            $this->action,
            $this->changes,
            $previousHash,
            self::hashOf($linked),
        );
    }

    /**
     * What is left of a record after an erasure (F-CORE-040): the link, and nothing else.
     *
     * **A lawful erasure and a manipulation must not look alike.** Deleting the row outright would
     * break the chain at the successor and leave it broken for good, so every later verification
     * would report tampering that never happened — and a warning that is always on is a warning
     * nobody reads. The shell keeps previousRecordHash and recordHash so the linkage stays
     * checkable, and drops everything the erasure was about.
     *
     * The honest limit, and it is the point rather than a flaw: a shell's *content* can no longer be
     * verified against its hash, because there is no content left to hash. Linkage stays provable,
     * content integrity of the erased record does not. If it did, the erasure would not be one.
     */
    public function redactedShell(): self
    {
        return new self(
            $this->id,
            $this->at,
            self::REDACTED,
            self::REDACTED,
            $this->id,
            self::REDACTED,
            [],
            $this->previousRecordHash,
            $this->recordHash,
        );
    }

    public function isRedacted(): bool
    {
        return $this->objectType === self::REDACTED;
    }

    /**
     * The hash of a record: SHA-256 over its canonical JSON (RFC 8785) **without** recordHash itself.
     *
     * Both languages hash the same bytes because both produce the same canonical JSON — the property
     * the cross-test already proves for journalExport.
     */
    public static function hashOf(self $record): string
    {
        $payload = $record->jsonSerialize();
        unset($payload['recordHash']);

        return hash('sha256', CanonicalJson::encode($payload));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'at' => Timestamp::canonical($this->at),
            'actor' => $this->actor,
            'objectType' => $this->objectType,
            'objectId' => $this->objectId->value,
            'action' => $this->action,
            'changes' => $this->changes === [] ? new \stdClass() : $this->changes,
            'previousRecordHash' => $this->previousRecordHash,
            'recordHash' => $this->recordHash,
        ];
    }
}
