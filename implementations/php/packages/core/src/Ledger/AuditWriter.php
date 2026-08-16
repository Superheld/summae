<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

use DateTimeImmutable;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Uuid;

/**
 * Writes the audit trail for the ledger services.
 *
 * Extracted because Ledger, SettlementService and ChartAdminService all need the same three
 * things — who acted, what time it is, and the record itself — and sharing them through a base
 * class would tie services together that otherwise have nothing in common.
 */
final readonly class AuditWriter
{
    public function __construct(
        private AuditTrail $audit,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
    }

    /**
     * The actor of an operation; absent or empty means the system itself.
     *
     * @param array<string, mixed> $input
     */
    public function actorOf(array $input): string
    {
        return is_string($input['actor'] ?? null) && $input['actor'] !== '' ? $input['actor'] : 'system';
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    public function record(string $actor, string $objectType, Uuid $objectId, string $action, array $changes = []): void
    {
        $this->audit->append(new AuditRecord(
            $this->ids->next(),
            $this->clock->now(),
            $actor,
            $objectType,
            $objectId,
            $action,
            $changes,
        ));
    }
}
