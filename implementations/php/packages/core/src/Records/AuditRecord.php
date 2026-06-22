<?php

declare(strict_types=1);

namespace Summae\Core\Records;

use Summae\Core\Substrate\Timestamp;
use Summae\Core\Substrate\Uuid;

/**
 * Audit-Eintrag (datenformat.md v0.3 `auditLog.jsonl`):
 * flacher Vorher/Nachher-Diff nur der geänderten Felder.
 */
final readonly class AuditRecord implements \JsonSerializable
{
    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    public function __construct(
        public Uuid $id,
        public \DateTimeImmutable $at,
        public string $actor,
        public string $objectType,
        public Uuid $objectId,
        public string $action,
        public array $changes = [],
    ) {
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
        ];
    }
}
