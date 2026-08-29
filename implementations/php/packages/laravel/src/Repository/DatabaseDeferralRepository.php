<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Policies\Expansion\Deferrals\Deferral;
use Summae\Core\Port\DeferralRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * Prepaid and deferred items — payload as JSON, one row each (F-CORE-053).
 *
 * `save` rewrites the payload because the release run appends to it, month after month. What must
 * survive is not only the plan but *which instalments have already run*: a release run that decided
 * from a balance rather than from a record would book a period twice after a restart, and the whole
 * point of the operation is that nobody has to remember.
 */
final readonly class DatabaseDeferralRepository implements DeferralRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
        private Currency $currency,
    ) {
    }

    public function add(Deferral $deferral): void
    {
        $this->table()->insert([
            'id' => $deferral->id->value,
            'tenant_id' => $this->tenantId->value,
            'kind' => $deferral->kind,
            'status' => $deferral->isSettled() ? 'settled' : 'open',
            'payload' => Hydrator::encode($deferral->jsonSerialize()),
        ]);
    }

    public function save(Deferral $deferral): void
    {
        $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('id', $deferral->id->value)
            ->update([
                'status' => $deferral->isSettled() ? 'settled' : 'open',
                'payload' => Hydrator::encode($deferral->jsonSerialize()),
            ]);
    }

    public function all(): array
    {
        $deferrals = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->orderBy('id')->get() as $row) {
            $deferrals[] = $this->hydrate($row);
        }

        return $deferrals;
    }

    private function hydrate(object $row): Deferral
    {
        /** @var object{id: string, payload: string} $row */
        $data = Hydrator::decode($row->payload);

        $plan = [];
        foreach (is_array($data['plan'] ?? null) ? $data['plan'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            /** @var array<string, mixed> $entryMoney */
            $entryMoney = is_array($entry['amount'] ?? null) ? $entry['amount'] : [];
            $plan[] = [
                'fiscalYear' => is_int($entry['fiscalYear'] ?? null) ? $entry['fiscalYear'] : 0,
                'period' => is_int($entry['period'] ?? null) ? $entry['period'] : 0,
                'amount' => Hydrator::money($entryMoney, $this->currency),
            ];
        }

        $released = [];
        foreach (is_array($data['released'] ?? null) ? $data['released'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['entryId'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $entryMoney */
            $entryMoney = is_array($entry['amount'] ?? null) ? $entry['amount'] : [];
            $released[] = [
                'fiscalYear' => is_int($entry['fiscalYear'] ?? null) ? $entry['fiscalYear'] : 0,
                'period' => is_int($entry['period'] ?? null) ? $entry['period'] : 0,
                'amount' => Hydrator::money($entryMoney, $this->currency),
                'date' => CalendarDate::of(is_string($entry['date'] ?? null) ? $entry['date'] : '1970-01-01'),
                'entryId' => Uuid::fromString($entry['entryId']),
            ];
        }

        $recognitionEntryId = $data['recognitionEntryId'] ?? null;

        return Deferral::restore(
            Uuid::fromString($row->id),
            is_string($data['kind'] ?? null) ? $data['kind'] : '',
            is_string($data['reason'] ?? null) ? $data['reason'] : '',
            AccountNumber::of(is_string($data['account'] ?? null) ? $data['account'] : ''),
            AccountNumber::of(is_string($data['counterAccount'] ?? null) ? $data['counterAccount'] : ''),
            CalendarDate::of(is_string($data['recognizedOn'] ?? null) ? $data['recognizedOn'] : '1970-01-01'),
            $this->money($data['amount'] ?? null),
            $plan,
            $released,
            is_string($recognitionEntryId) ? Uuid::fromString($recognitionEntryId) : null,
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
        return $this->connection->table(SchemaInstaller::PREFIX . 'deferrals');
    }
}
