<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Policies\Expansion\Provisions\Provision;
use Summae\Core\Port\ProvisionRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * Provisions — payload as JSON, one row per provision (F-CORE-051).
 *
 * `save` rewrites the payload, because a provision changes over years: used, released, re-measured.
 * The movement list travels with it, so the register is the same history after a restart as it was
 * before — which is the whole reason it is a record and not an account balance.
 */
final readonly class DatabaseProvisionRepository implements ProvisionRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
        private Currency $currency,
    ) {
    }

    public function add(Provision $provision): void
    {
        $this->table()->insert([
            'id' => $provision->id->value,
            'tenant_id' => $this->tenantId->value,
            'account' => $provision->account->value,
            'status' => $provision->status(),
            'payload' => Hydrator::encode($provision->jsonSerialize()),
        ]);
    }

    public function save(Provision $provision): void
    {
        $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('id', $provision->id->value)
            ->update(['status' => $provision->status(), 'payload' => Hydrator::encode($provision->jsonSerialize())]);
    }

    public function byId(Uuid $id): ?Provision
    {
        $row = $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $provisions = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->orderBy('id')->get() as $row) {
            $provisions[] = $this->hydrate($row);
        }

        return $provisions;
    }

    private function hydrate(object $row): Provision
    {
        /** @var object{id: string, payload: string} $row */
        $data = Hydrator::decode($row->payload);

        $movements = [];
        foreach (is_array($data['movements'] ?? null) ? $data['movements'] : [] as $movement) {
            if (!is_array($movement)) {
                continue;
            }
            $entryId = $movement['entryId'] ?? null;
            $amount = $movement['amount'] ?? null;
            $movements[] = [
                'kind' => is_string($movement['kind'] ?? null) ? $movement['kind'] : '',
                'date' => CalendarDate::of(is_string($movement['date'] ?? null) ? $movement['date'] : '1970-01-01'),
                /** @phpstan-ignore-next-line shape restored from the payload it was written from */
                'amount' => is_array($amount) ? Hydrator::money($amount, $this->currency) : Money::zero($this->currency),
                'entryId' => is_string($entryId) ? Uuid::fromString($entryId) : null,
                'note' => is_string($movement['note'] ?? null) ? $movement['note'] : null,
            ];
        }

        $dueDate = $data['dueDate'] ?? null;

        return Provision::restore(
            Uuid::fromString($row->id),
            is_string($data['reason'] ?? null) ? $data['reason'] : '',
            AccountNumber::of(is_string($data['account'] ?? null) ? $data['account'] : ''),
            AccountNumber::of(is_string($data['expenseAccount'] ?? null) ? $data['expenseAccount'] : ''),
            AccountNumber::of(is_string($data['releaseAccount'] ?? null) ? $data['releaseAccount'] : ''),
            CalendarDate::of(is_string($data['recognizedOn'] ?? null) ? $data['recognizedOn'] : '1970-01-01'),
            is_string($dueDate) ? CalendarDate::of($dueDate) : null,
            $this->money($data['settlementAmount'] ?? null),
            $this->money($data['carryingAmount'] ?? null),
            is_string($data['discountRate'] ?? null) ? $data['discountRate'] : null,
            $movements,
        );
    }

    private function money(mixed $raw): Money
    {
        if (!is_array($raw)) {
            return Money::zero($this->currency);
        }

        /** @var array<string, mixed> $raw */
        return Hydrator::money($raw, $this->currency);
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'provisions');
    }
}
