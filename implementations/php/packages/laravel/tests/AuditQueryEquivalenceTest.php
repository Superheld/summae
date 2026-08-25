<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Composition\TenantOperations;
use Summae\Core\Records\AuditFilter;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Substrate\Uuid;

/**
 * The SQL filter and the in-memory filter answer the same question the same way (SPEC-018).
 *
 * `AuditTrail::find` exists twice over: once as SQL that declines to read rows, once as
 * `AuditFilter` walking a list. That is the arrangement the quality policy calls a *shared data*
 * check — two implementations of one rule, driven with the same input, compared. Without it the
 * database path could quietly answer differently and every fixture would stay green, because
 * fixtures run against the in-memory core.
 *
 * The criteria below are not a sample: they are every filter the port declares, alone and in
 * combination, plus the paging edges (offset past the end, limit zero, an empty id set).
 *
 * The Node twin is in `packages/knex/test/adapter.test.ts`.
 */
final class AuditQueryEquivalenceTest extends AdapterTestCase
{
    /** @return list<array<string, mixed>> */
    private function criteria(string $entryId, string $accountId): array
    {
        return [
            [],
            ['objectType' => 'account'],
            ['objectType' => 'journalEntry', 'action' => 'created'],
            ['action' => 'created'],
            ['actor' => 'anna'],
            ['actor' => 'bruce'],
            ['actor' => 'niemand'],
            ['objectId' => $entryId],
            ['objectId' => $accountId],
            ['objectIds' => [$entryId, $accountId]],
            ['objectIds' => [$entryId]],
            // An empty set means "none of them", not "all of them" — the case a naive IN clause
            // gets exactly backwards.
            ['objectIds' => []],
            ['from' => '2026-06-07'],
            ['to' => '2026-06-07'],
            ['from' => '2026-06-08'],
            ['to' => '2026-06-06'],
            ['limit' => 2],
            ['offset' => 1, 'limit' => 2],
            ['offset' => 1],
            ['limit' => 0],
            ['offset' => 999],
            ['objectType' => 'account', 'actor' => 'anna', 'limit' => 1],
        ];
    }

    public function testTheDatabaseFilterAnswersLikeTheInMemoryOne(): void
    {
        $tenant = $this->tenantOn(Uuid::fromString('0195f000-0000-7000-8000-0000000000a1'));
        $ops = new TenantOperations($tenant);

        $ops->execute('createAccount', ['actor' => 'anna', 'number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);
        $ops->execute('createAccount', ['actor' => 'anna', 'number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue']);
        $ops->execute('createFiscalYear', ['actor' => 'bruce', 'year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        /** @var array<string, mixed> $voucher */
        $voucher = $ops->execute('createVoucher', [
            'actor' => 'bruce',
            'voucher' => ['voucherNumber' => 'AR-1', 'voucherDate' => '2026-02-01'],
        ]);
        self::assertIsString($voucher['id'] ?? null);
        /** @var array<string, mixed> $entry */
        $entry = $ops->execute('post', [
            'actor' => 'bruce',
            'entryDate' => '2026-02-01', 'voucherId' => $voucher['id'], 'text' => 'Rechnung',
            'lines' => [
                ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
                ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
            ],
        ]);
        self::assertIsString($entry['id'] ?? null);
        $ops->execute('lockAccount', ['actor' => 'anna', 'number' => '8400']);

        $trail = $tenant->audit;
        $all = $trail->all();
        self::assertGreaterThan(4, count($all), 'the trail needs enough records for paging to mean anything');

        $accountRecord = null;
        foreach ($all as $record) {
            if ($record->objectType === 'account') {
                $accountRecord = $record;
                break;
            }
        }
        self::assertInstanceOf(AuditRecord::class, $accountRecord);

        foreach ($this->criteria($entry['id'], $accountRecord->objectId->value) as $criteria) {
            $fromDatabase = $trail->find($criteria);
            $inMemory = AuditFilter::apply($all, $criteria);

            $label = json_encode($criteria, JSON_THROW_ON_ERROR);
            self::assertSame($inMemory['count'], $fromDatabase['count'], 'count differs for ' . $label);
            self::assertSame(
                array_map(static fn (AuditRecord $r): string => $r->id->value, $inMemory['records']),
                array_map(static fn (AuditRecord $r): string => $r->id->value, $fromDatabase['records']),
                'records differ for ' . $label,
            );
        }
    }
}
