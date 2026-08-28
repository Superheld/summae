<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

final readonly class DatabaseAuditTrail implements AuditTrail
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    /**
     * Appends the record linked behind the trail's current head (format 0.8).
     *
     * The head is read here rather than kept in the process, because the store is the only thing
     * that knows it: a second process appending to the same tenant would otherwise link behind a
     * head that had already moved. Two truly concurrent appends can still read the same head and
     * both link to it; that fork is reported as a break by auditTrailIntegrity rather than hidden,
     * because from the data alone a fork and a removal are the same picture. Serialising writes
     * stays the embedding's, like every other write here.
     */
    public function append(AuditRecord $record): void
    {
        $chained = $record->chainedTo($this->head());
        $data = $chained->jsonSerialize();
        $data['changes'] = $data['changes'] instanceof \stdClass ? [] : $data['changes'];

        $this->table()->insert([
            'id' => $chained->id->value,
            'tenant_id' => $this->tenantId->value,
            'payload' => Hydrator::encode($data),
        ]);
    }

    private function head(): ?string
    {
        $row = $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->orderByDesc('seq')
            ->first();
        if ($row === null) {
            return null;
        }

        /** @var object{payload: string} $row */
        $hash = Hydrator::decode($row->payload)['recordHash'] ?? null;

        return is_string($hash) ? $hash : null;
    }

    public function all(): array
    {
        return $this->hydrate($this->table()->where('tenant_id', $this->tenantId->value)->orderBy('seq')->get());
    }

    /**
     * The criteria pushed into SQL (SPEC-018).
     *
     * `objectType`, `action`, `actor`, `objectId` and the recording date live inside the JSON
     * payload rather than in columns, so they are extracted there. **Deliberately not columns:**
     * adding one is easy, filling it for rows that already exist is a data migration, and neither
     * language has a migration runner — an unfilled column would make the filter miss exactly the
     * history an audit is about, which is worse than no filter. Extraction costs the index and
     * keeps correctness, and correctness is not the half to trade.
     *
     * Dialect-specific by necessity and only here: SQLite reads JSON with `json_extract`, Postgres
     * with `->>`. Different SQL, identical rows — `seq` still decides the order, and the adapter
     * suite drives the same criteria through this and through the in-memory filter and compares.
     */
    /**
     * F-CORE-040 — the only statement in this adapter that removes a row from the trail.
     *
     * Same dialect split as find(): objectType/objectId live in the JSON payload, so the delete
     * extracts them the way the filter does. Tenant-scoped, like everything else here. The count is
     * what delete() reports, which is the number of rows that actually went.
     */
    /**
     * F-CORE-040 — and since format 0.8 no longer a delete.
     *
     * The rows have to stay, because each one carries a link of the hash chain. Deleting them would
     * break the chain at the successor for good, and every later verification would report a
     * manipulation that never happened — a warning that is always on is a warning nobody reads. What
     * goes is the content; what stays is the shell and its two hashes.
     */
    public function eraseFor(string $objectType, Uuid $objectId): int
    {
        $postgres = $this->isPostgres();
        $matching = $this->hydrate(
            $this->table()
                ->where('tenant_id', $this->tenantId->value)
                ->whereRaw(AuditSql::equals($postgres, 'objectType'), [$objectType])
                ->whereRaw(AuditSql::equals($postgres, 'objectId'), [$objectId->value])
                ->orderBy('seq')
                ->get(),
        );

        foreach ($matching as $record) {
            $data = $record->redactedShell()->jsonSerialize();
            $data['changes'] = $data['changes'] instanceof \stdClass ? [] : $data['changes'];
            $this->table()
                ->where('tenant_id', $this->tenantId->value)
                ->where('id', $record->id->value)
                ->update(['payload' => Hydrator::encode($data)]);
        }

        return count($matching);
    }

    public function find(array $criteria): array
    {
        $query = $this->table()->where('tenant_id', $this->tenantId->value);

        foreach (['objectType', 'action', 'actor', 'objectId'] as $field) {
            $wanted = $criteria[$field] ?? null;
            if (is_string($wanted)) {
                $query->whereRaw(AuditSql::equals($this->isPostgres(), $field), [$wanted]);
            }
        }

        $objectIds = $criteria['objectIds'] ?? null;
        if (is_array($objectIds)) {
            $values = array_values(array_filter($objectIds, is_string(...)));
            $predicate = AuditSql::equals($this->isPostgres(), 'objectId');
            // A group of ORs rather than an IN list: the placeholder list of an IN clause has to be
            // built as a string, and raw SQL here is required to be a literal — a rule worth keeping
            // exactly where user-supplied ids meet the query. An empty set matches nothing, because
            // "these entries" with none of them is not "all of them".
            $query->where(static function (Builder $group) use ($values, $predicate): void {
                if ($values === []) {
                    $group->whereRaw('1 = 0');

                    return;
                }

                foreach ($values as $value) {
                    $group->orWhereRaw($predicate, [$value]);
                }
            });
        }

        // `at` is a canonical UTC timestamp, so its first ten characters are the calendar date and
        // compare as one.
        $from = $criteria['from'] ?? null;
        if (is_string($from)) {
            $query->whereRaw(AuditSql::dateAtLeast($this->isPostgres()), [$from]);
        }
        $to = $criteria['to'] ?? null;
        if (is_string($to)) {
            $query->whereRaw(AuditSql::dateAtMost($this->isPostgres()), [$to]);
        }

        $count = (clone $query)->count();

        $offset = max(0, is_int($criteria['offset'] ?? null) ? $criteria['offset'] : 0);
        $limit = is_int($criteria['limit'] ?? null) ? $criteria['limit'] : null;

        $page = $query->orderBy('seq');

        // Paging is pushed down only when there IS a limit: SQLite refuses an OFFSET without one,
        // and without a limit the caller has asked for every remaining row anyway — so the offset
        // costs nothing to apply after reading. With a limit, both travel and the store reads a
        // page, which is the case worth optimising.
        if ($limit !== null && $limit >= 0) {
            $records = $this->hydrate($page->limit($limit)->offset($offset)->get());
        } else {
            $records = array_slice($this->hydrate($page->get()), $offset);
        }

        return ['records' => $records, 'count' => $count];
    }

    /**
     * `getDriverName()` is on the concrete Connection, not on ConnectionInterface — the same seam
     * AdapterTestCase notes for the schema builder. Anything else is read as SQLite, which is the
     * portable syntax here.
     */
    private function isPostgres(): bool
    {
        $connection = $this->connection;

        return $connection instanceof \Illuminate\Database\Connection && $connection->getDriverName() === 'pgsql';
    }

    /**
     * @param \Illuminate\Support\Collection<int, mixed> $rows
     *
     * @return list<AuditRecord>
     */
    private function hydrate(\Illuminate\Support\Collection $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            /** @var object{payload: string} $row */
            $data = Hydrator::decode($row->payload);

            /** @var array<string, array{from: mixed, to: mixed}> $changes */
            $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];

            $records[] = new AuditRecord(
                Uuid::fromString(is_string($data['id'] ?? null) ? $data['id'] : ''),
                new \DateTimeImmutable(is_string($data['at'] ?? null) ? $data['at'] : 'now'),
                is_string($data['actor'] ?? null) ? $data['actor'] : 'system',
                is_string($data['objectType'] ?? null) ? $data['objectType'] : '',
                Uuid::fromString(is_string($data['objectId'] ?? null) ? $data['objectId'] : ''),
                is_string($data['action'] ?? null) ? $data['action'] : '',
                $changes,
                // null for anything written before format 0.8 — the difference between a record that
                // has no hash and one whose hash does not match is what auditTrailIntegrity reports
                // as unchained rather than broken.
                is_string($data['previousRecordHash'] ?? null) ? $data['previousRecordHash'] : null,
                is_string($data['recordHash'] ?? null) ? $data['recordHash'] : null,
            );
        }

        return $records;
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'audit_log');
    }
}
