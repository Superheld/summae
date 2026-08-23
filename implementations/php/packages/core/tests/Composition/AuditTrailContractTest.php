<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Composition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;

/**
 * Contract test for audit-trail completeness (F-CORE-014, F-CORE-020; GoBD Rz. 107 ff.).
 *
 * The behavioural fixtures prove that individual operations produce the right numbers.
 * They do NOT prove that a *state-changing* operation leaves a trace: a fixture only sees
 * what it asserts, so an operation that silently mutates bookkeeping-relevant state and
 * writes no audit record passes every fixture in the suite. That is exactly how
 * `setTaxProfile`, `importMapping`, `setAllocationScheme` and all four period operations
 * went unlogged while F-CORE-014 counted as covered — the one fixture backing it
 * (`core/audit-trail.json`) exercises accounts only.
 *
 * So this test enumerates the operations instead of sampling them: every case below runs
 * for real and must add at least one audit record with the stated objectType and action.
 * An operation added later without a trace fails here, in the language it was added in.
 *
 * The SAME list lives in the Node audit-trail-contract.test.ts. Read-only operations
 * (projections, `expandTax`) are deliberately absent: they change nothing, so there is
 * nothing to log.
 */
final class AuditTrailContractTest extends TestCase
{
    /**
     * Operations that mutate but are not yet pinned here. Each one is a gap, not an
     * exemption: they post through the ledger (so the journalEntry trace exists) but write
     * no record of their own — `acquireAsset` leaves "journalEntry/created" and nothing
     * saying an asset was acquired. Shrinking this list is the work; growing it needs a
     * reason in the commit.
     *
     * @var list<string>
     */
    private const UNCOVERED_KNOWN = [
        'postVoucher',
        'createVoucher',
        'settle',
        'acquireAsset',
        'disposeAsset',
        'runDepreciation',
        'allocate',
        'runCosting',
        'releaseCosting',
        'importChartOfAccounts',
    ];

    private function freshOps(): TenantOperations
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $tenant = Tenant::inMemory('Audit GmbH', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));

        return new TenantOperations($tenant);
    }

    /** Accounts, a fiscal year and a voucher — the ground state most operations need. */
    private function seed(TenantOperations $ops): string
    {
        $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);
        $ops->execute('createAccount', ['number' => '4930', 'name' => 'Bürobedarf', 'type' => 'expense']);
        $ops->execute('createAccount', ['number' => '1600', 'name' => 'Verbindlichkeiten', 'type' => 'liability']);
        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        /** @var array<string, mixed> $voucher */
        $voucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'ER-2026-001', 'voucherDate' => '2026-01-20'],
        ]);

        $voucherId = $voucher['id'] ?? null;
        self::assertIsString($voucherId, 'createVoucher must return the voucher id');

        return $voucherId;
    }

    private function postOne(TenantOperations $ops, string $voucherId, string $date = '2026-01-20'): string
    {
        /** @var array<string, mixed> $result */
        $result = $ops->execute('post', [
            'entryDate' => $date,
            'voucherId' => $voucherId,
            'text' => 'Bürobedarf',
            'lines' => [
                ['account' => '4930', 'side' => 'debit', 'money' => ['amount' => '240.00', 'currency' => 'EUR']],
                ['account' => '1200', 'side' => 'credit', 'money' => ['amount' => '240.00', 'currency' => 'EUR']],
            ],
        ]);

        $entryId = $result['id'] ?? null;
        self::assertIsString($entryId, 'post must return the entry id');

        return $entryId;
    }

    /** @return list<array<string, mixed>> */
    private function auditRecords(TenantOperations $ops): array
    {
        /** @var array<string, mixed> $log */
        $log = $ops->project('auditLog', []);
        /** @var list<array<string, mixed>> $records */
        $records = is_array($log['records'] ?? null) ? $log['records'] : [];

        return $records;
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function auditedOperations(): iterable
    {
        // --- ledger ---------------------------------------------------------
        yield 'createAccount' => ['createAccount', 'account', 'created'];
        yield 'lockAccount' => ['lockAccount', 'account', 'locked'];
        yield 'post' => ['post', 'journalEntry', 'created'];
        yield 'correct' => ['correct', 'journalEntry', 'corrected'];
        yield 'finalize' => ['finalize', 'journalEntry', 'finalized'];
        yield 'reverse' => ['reverse', 'journalEntry', 'reversed'];
        // --- periods: the operations GoBD Rz. 107 ff. cares about most -------
        yield 'createFiscalYear' => ['createFiscalYear', 'fiscalYear', 'created'];
        yield 'closePeriod' => ['closePeriod', 'period', 'closed'];
        // Reopening a closed period is the single most audit-relevant act in the whole
        // API: it takes back a lock. It used to leave no trace at all.
        yield 'reopenPeriod' => ['reopenPeriod', 'period', 'reopened'];
        yield 'closeFiscalYear' => ['closeFiscalYear', 'fiscalYear', 'closed'];
        // --- tenant-level configuration (F-CORE-014 "Steuerschlüssel, Profile")
        yield 'setTaxProfile' => ['setTaxProfile', 'taxProfile', 'changed'];
        yield 'importMapping' => ['importMapping', 'mapping', 'imported'];
        yield 'setAllocationScheme' => ['setAllocationScheme', 'allocationScheme', 'changed'];
        // --- partners --------------------------------------------------------
        yield 'createPartner' => ['createPartner', 'partner', 'created'];
        yield 'updatePartner' => ['updatePartner', 'partner', 'updated'];
    }

    private function runOperation(TenantOperations $ops, string $op): void
    {
        switch ($op) {
            case 'createAccount':
                $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);

                return;
            case 'lockAccount':
                $this->seed($ops);
                $ops->execute('lockAccount', ['number' => '4930']);

                return;
            case 'post':
                $this->postOne($ops, $this->seed($ops));

                return;
            case 'correct':
                $entryId = $this->postOne($ops, $this->seed($ops));
                $ops->execute('correct', ['entryId' => $entryId, 'text' => 'Bürobedarf Januar']);

                return;
            case 'finalize':
                $this->postOne($ops, $this->seed($ops));
                $ops->execute('finalize', ['finalizeUntil' => '2026-01-31']);

                return;
            case 'reverse':
                $entryId = $this->postOne($ops, $this->seed($ops));
                $ops->execute('reverse', ['entryId' => $entryId, 'entryDate' => '2026-01-25', 'text' => 'Storno']);

                return;
            case 'createFiscalYear':
                $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);

                return;
            case 'closePeriod':
                $this->seed($ops);
                $ops->execute('closePeriod', ['fiscalYear' => 2026, 'period' => 1]);

                return;
            case 'reopenPeriod':
                $this->seed($ops);
                $ops->execute('closePeriod', ['fiscalYear' => 2026, 'period' => 1]);
                $ops->execute('reopenPeriod', ['fiscalYear' => 2026, 'period' => 1]);

                return;
            case 'closeFiscalYear':
                $voucherId = $this->seed($ops);
                $this->postOne($ops, $voucherId);
                $ops->execute('finalize', ['finalizeUntil' => '2026-12-31']);
                for ($period = 1; $period <= 12; $period++) {
                    $ops->execute('closePeriod', ['fiscalYear' => 2026, 'period' => $period]);
                }
                $ops->execute('closeFiscalYear', ['fiscalYear' => 2026]);

                return;
            case 'setTaxProfile':
                $ops->execute('setTaxProfile', ['smallBusiness' => ['validFrom' => '2026-01-01', 'value' => true]]);

                return;
            case 'importMapping':
                $this->seed($ops);
                $ops->execute('importMapping', ['mapping' => [
                    'id' => 'test-bilanz',
                    'kind' => 'balance-sheet',
                    'nodes' => [
                        ['key' => 'assets', 'label' => 'Aktiva', 'side' => 'assets', 'accounts' => ['1200']],
                        ['key' => 'liabilities', 'label' => 'Passiva', 'side' => 'liabilitiesAndEquity', 'accounts' => ['1600']],
                    ],
                ]]);

                return;
            case 'setAllocationScheme':
                $ops->execute('setAllocationScheme', [
                    'method' => 'step_ladder',
                    'steps' => [['sender' => 'HK1', 'receivers' => [['code' => 'K1', 'share' => '1']]]],
                ]);

                return;
            case 'createPartner':
                $ops->execute('createPartner', ['number' => 'D-1000', 'name' => 'Kunde AG', 'role' => 'customer']);

                return;
            case 'updatePartner':
                /** @var array<string, mixed> $partner */
                $partner = $ops->execute('createPartner', ['number' => 'D-1000', 'name' => 'Kunde AG', 'role' => 'customer']);
                $partnerId = $partner['id'] ?? null;
                self::assertIsString($partnerId, 'createPartner must return the partner id');
                $ops->execute('updatePartner', ['partnerId' => $partnerId, 'name' => 'Kunde SE']);

                return;
            default:
                self::fail(sprintf('no run recipe for %s', $op));
        }
    }

    #[DataProvider('auditedOperations')]
    public function testOperationLeavesAnAuditRecord(string $op, string $objectType, string $action): void
    {
        $ops = $this->freshOps();
        $this->runOperation($ops, $op);

        $matches = array_filter(
            $this->auditRecords($ops),
            static fn (array $r): bool => ($r['objectType'] ?? null) === $objectType && ($r['action'] ?? null) === $action,
        );

        self::assertNotEmpty($matches, sprintf(
            '%s must write an audit record %s/%s — a state change without a trace is a GoBD defect, '
            .'not a missing convenience',
            $op,
            $objectType,
            $action,
        ));
    }

    public function testRecordsCarryActorTimestampAndObjectIdentity(): void
    {
        $ops = $this->freshOps();
        $this->seed($ops);
        $ops->execute('closePeriod', ['fiscalYear' => 2026, 'period' => 1, 'actor' => 'bruce']);

        $closed = array_values(array_filter(
            $this->auditRecords($ops),
            static fn (array $r): bool => ($r['action'] ?? null) === 'closed',
        ));

        self::assertNotEmpty($closed, 'closePeriod must be in the log');
        self::assertSame('bruce', $closed[0]['actor'] ?? null);
        self::assertSame('2026-06-07T10:00:00.000Z', $closed[0]['at'] ?? null);
        self::assertIsString($closed[0]['objectId'] ?? null);
        self::assertIsArray($closed[0]['changes'] ?? null);
    }

    public function testAbsentActorIsRecordedAsSystem(): void
    {
        $ops = $this->freshOps();
        $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);

        self::assertSame('system', $this->auditRecords($ops)[0]['actor'] ?? null);
    }

    public function testEveryStateChangingOperationIsClaimedByThisList(): void
    {
        // The guard against the guard: a new mutating operation must be added above, or
        // this fails. `expandTax` is listed as read-only — it computes and changes nothing.
        $readOnly = ['expandTax'];
        $mutating = array_values(array_diff([
            'expandTax', 'setTaxProfile', 'postVoucher', 'createVoucher', 'post', 'correct',
            'finalize', 'reverse', 'settle', 'closePeriod', 'reopenPeriod', 'closeFiscalYear',
            'createAccount', 'createFiscalYear', 'createPartner', 'updatePartner', 'acquireAsset',
            'disposeAsset', 'runDepreciation', 'allocate', 'setAllocationScheme', 'runCosting',
            'releaseCosting', 'lockAccount', 'importChartOfAccounts', 'importMapping',
        ], $readOnly));

        $declared = [];
        foreach (self::auditedOperations() as $case) {
            $declared[] = $case[0];
        }

        $uncovered = array_values(array_diff($mutating, $declared));

        self::assertSame(
            self::UNCOVERED_KNOWN,
            $uncovered,
            'these operations change state but no audit-completeness case claims them — '
            .'add a case above, or move the operation to the read-only list with a reason',
        );
    }
}
