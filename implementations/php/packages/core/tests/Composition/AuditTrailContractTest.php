<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Composition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Policies\Projection\SystemDescriptionProjection;
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
     * Operations that mutate but are not yet pinned here. The list is EMPTY, and keeping it
     * that way is the point: every state-changing operation in the dispatcher now writes a
     * record of its own kind, not merely the `journalEntry/created` its postings leave
     * behind. An entry here needs a reason in the commit that adds it.
     *
     * @var list<string>
     */
    private const UNCOVERED_KNOWN = [];

    private function freshOps(): TenantOperations
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $tenant = Tenant::inMemory('Audit GmbH', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));
        // The asset operations need the pack data they would normally be composed with;
        // without it `acquireAsset` fails on the missing useful life instead of on the thing
        // under test.
        $tenant->assetService->setRuleModule([
            'usefulLife' => [['assetClass' => 'machinery', 'months' => 60]],
            // The allowance the asset operations need before they can be audited at all: an
            // elected special depreciation needs a declared one to draw on.
            'specialDepreciation' => [['validFrom' => '2024-01-01', 'validTo' => null, 'rate' => '40.00', 'years' => 5]],
            'assetAccounts' => [
                'acquisitionCounterAccount' => '1200',
                'depreciationExpenseAccount' => '4830',
                'accumulatedDepreciationAccount' => '0400',
                'gwgExpenseAccount' => '4930',
                'disposalGainAccount' => '8400',
                'disposalLossAccount' => '4930',
            ],
        ]);

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
        yield 'unlockAccount' => ['unlockAccount', 'account', 'unlocked'];
        yield 'defineDimensionType' => ['defineDimensionType', 'dimensionType', 'created'];
        yield 'defineDimensionValue' => ['defineDimensionValue', 'dimensionValue', 'created'];
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
        yield 'deactivatePartner' => ['deactivatePartner', 'partner', 'deactivated'];
        yield 'reactivatePartner' => ['reactivatePartner', 'partner', 'reactivated'];
        // --- vouchers, settlements, assets, costing --------------------------
        yield 'createVoucher' => ['createVoucher', 'voucher', 'created'];
        yield 'postVoucher' => ['postVoucher', 'voucher', 'created'];
        yield 'settle' => ['settle', 'openItem', 'settled'];
        yield 'importChartOfAccounts' => ['importChartOfAccounts', 'account', 'created'];
        yield 'acquireAsset' => ['acquireAsset', 'asset', 'acquired'];
        yield 'disposeAsset' => ['disposeAsset', 'asset', 'disposed'];
        yield 'writeDownAsset' => ['writeDownAsset', 'asset', 'writtenDown'];
        yield 'bookSpecialDepreciation' => ['bookSpecialDepreciation', 'asset', 'specialDepreciationBooked'];
        yield 'reportAssetUsage' => ['reportAssetUsage', 'asset', 'usageReported'];
        yield 'runDepreciation' => ['runDepreciation', 'depreciationRun', 'completed'];
        yield 'runCosting' => ['runCosting', 'costingRun', 'created'];
        yield 'releaseCosting' => ['releaseCosting', 'costingRun', 'released'];
    }

    /** @return array<string, mixed> */
    private function assetInput(string $voucherId): array
    {
        return [
            'name' => 'Maschine',
            'assetClass' => 'machinery',
            'assetAccount' => '0400',
            'acquisitionCost' => ['amount' => '5000.00', 'currency' => 'EUR'],
            'acquiredOn' => '2026-01-15',
            'usefulLifeMonths' => 60,
            'voucherId' => $voucherId,
        ];
    }

    /**
     * The asset most of the asset cases need — one place, so a changed input shape moves once.
     *
     * @param array<string, mixed> $extra
     */
    private function acquire(TenantOperations $ops, array $extra = []): string
    {
        $voucherId = $this->seed($ops);
        $ops->execute('createAccount', ['number' => '0400', 'name' => 'Maschinen', 'type' => 'asset']);
        $ops->execute('createAccount', ['number' => '4830', 'name' => 'AfA', 'type' => 'expense']);
        /** @var array<string, mixed> $asset */
        $asset = $ops->execute('acquireAsset', array_merge($this->assetInput($voucherId), $extra));
        $assetId = $asset['id'] ?? null;
        self::assertIsString($assetId);

        return $assetId;
    }

    private function runOperation(TenantOperations $ops, string $op): void
    {
        switch ($op) {
            case 'createAccount':
                $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);

                return;
            case 'unlockAccount':
                $this->seed($ops);
                $ops->execute('lockAccount', ['number' => '4930']);
                $ops->execute('unlockAccount', ['number' => '4930']);

                return;
            case 'defineDimensionType':
                $ops->execute('defineDimensionType', ['code' => 'costCenter']);

                return;
            case 'defineDimensionValue':
                $ops->execute('defineDimensionType', ['code' => 'costCenter']);
                $ops->execute('defineDimensionValue', ['type' => 'costCenter', 'code' => 'K100']);

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
                $ops->execute('createPartner', ['name' => 'Kunde AG', 'kind' => 'customer']);

                return;
            case 'updatePartner':
                /** @var array<string, mixed> $partner */
                $partner = $ops->execute('createPartner', ['name' => 'Kunde AG', 'kind' => 'customer']);
                $partnerId = $partner['id'] ?? null;
                self::assertIsString($partnerId, 'createPartner must return the partner id');
                $ops->execute('updatePartner', ['partnerId' => $partnerId, 'name' => 'Kunde SE']);

                return;
            case 'deactivatePartner':
                /** @var array<string, mixed> $partner */
                $partner = $ops->execute('createPartner', ['name' => 'Kunde AG', 'kind' => 'customer']);
                self::assertIsString($partner['id'] ?? null);
                $ops->execute('deactivatePartner', ['partnerId' => $partner['id']]);

                return;
            case 'reactivatePartner':
                /** @var array<string, mixed> $partner */
                $partner = $ops->execute('createPartner', ['name' => 'Kunde AG', 'kind' => 'customer']);
                self::assertIsString($partner['id'] ?? null);
                $ops->execute('deactivatePartner', ['partnerId' => $partner['id']]);
                $ops->execute('reactivatePartner', ['partnerId' => $partner['id']]);

                return;
            case 'createVoucher':
                $this->seed($ops);

                return;
            case 'postVoucher':
                $this->seed($ops);
                $ops->execute('postVoucher', [
                    'voucher' => ['voucherNumber' => 'ER-2026-002', 'voucherDate' => '2026-01-21'],
                    'entryDate' => '2026-01-21',
                    'text' => 'Direktbuchung',
                    'lines' => [
                        ['account' => '4930', 'side' => 'debit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
                        ['account' => '1200', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
                    ],
                ]);

                return;
            case 'settle':
                $voucherId = $this->seed($ops);
                $ops->execute('createAccount', ['number' => '1400', 'name' => 'Forderungen', 'type' => 'asset', 'subtype' => 'ar']);
                $ops->execute('createAccount', ['number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue']);
                $ops->execute('post', [
                    'entryDate' => '2026-01-20',
                    'voucherId' => $voucherId,
                    'text' => 'Rechnung',
                    'lines' => [
                        ['account' => '1400', 'side' => 'debit', 'money' => ['amount' => '119.00', 'currency' => 'EUR']],
                        ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '119.00', 'currency' => 'EUR']],
                    ],
                ]);
                // The payment is an ordinary posting; `settle` then points that entry at the open item.
                /** @var array<string, mixed> $payment */
                $payment = $ops->execute('post', [
                    'entryDate' => '2026-01-25',
                    'voucherId' => $voucherId,
                    'text' => 'Zahlung',
                    'lines' => [
                        ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '119.00', 'currency' => 'EUR']],
                        ['account' => '1400', 'side' => 'credit', 'money' => ['amount' => '119.00', 'currency' => 'EUR']],
                    ],
                ]);
                /** @var array<string, mixed> $open */
                $open = $ops->project('openItems', []);
                /** @var list<array<string, mixed>> $items */
                $items = is_array($open['items'] ?? null) ? $open['items'] : [];
                $entryId = $payment['id'] ?? null;
                $itemId = $items[0]['id'] ?? null;
                self::assertIsString($entryId);
                self::assertIsString($itemId);
                $ops->execute('settle', [
                    'entryId' => $entryId,
                    'allocations' => [['openItemId' => $itemId, 'money' => ['amount' => '119.00', 'currency' => 'EUR']]],
                ]);

                return;
            case 'importChartOfAccounts':
                $ops->execute('importChartOfAccounts', [
                    'rows' => [['number' => '4980', 'name' => 'Sonstiges', 'type' => 'expense']],
                ]);

                return;
            case 'acquireAsset':
                $voucherId = $this->seed($ops);
                $ops->execute('createAccount', ['number' => '0400', 'name' => 'Maschinen', 'type' => 'asset']);
                $ops->execute('createAccount', ['number' => '4830', 'name' => 'AfA', 'type' => 'expense']);
                $ops->execute('acquireAsset', $this->assetInput($voucherId));

                return;
            case 'disposeAsset':
                $voucherId = $this->seed($ops);
                $ops->execute('createAccount', ['number' => '0400', 'name' => 'Maschinen', 'type' => 'asset']);
                $ops->execute('createAccount', ['number' => '4830', 'name' => 'AfA', 'type' => 'expense']);
                /** @var array<string, mixed> $asset */
                $asset = $ops->execute('acquireAsset', $this->assetInput($voucherId));
                $assetId = $asset['id'] ?? null;
                self::assertIsString($assetId);
                $ops->execute('disposeAsset', ['assetId' => $assetId, 'disposedOn' => '2026-06-30', 'voucherId' => $voucherId]);

                return;
            case 'writeDownAsset':
                $ops->execute('writeDownAsset', [
                    'assetId' => $this->acquire($ops),
                    'amount' => ['amount' => '1000.00', 'currency' => 'EUR'],
                    'date' => '2026-06-30',
                    'reason' => 'Wasserschaden',
                ]);

                return;
            case 'bookSpecialDepreciation':
                $ops->execute('bookSpecialDepreciation', [
                    'assetId' => $this->acquire($ops, ['specialDepreciation' => true]),
                    'fiscalYear' => 2026,
                    'amount' => ['amount' => '500.00', 'currency' => 'EUR'],
                ]);

                return;
            case 'reportAssetUsage':
                $ops->execute('reportAssetUsage', [
                    'assetId' => $this->acquire($ops, [
                        'totalUnits' => 100000,
                        'depreciationMethod' => 'units_of_production',
                    ]),
                    'fiscalYear' => 2026,
                    'units' => 10000,
                ]);

                return;
            case 'runDepreciation':
                $this->seed($ops);
                $ops->execute('runDepreciation', ['fiscalYear' => 2026, 'period' => 12]);

                return;
            case 'runCosting':
                $this->seed($ops);
                $ops->execute('runCosting', ['fiscalYear' => 2026, 'period' => 1]);

                return;
            case 'releaseCosting':
                $this->seed($ops);
                /** @var array<string, mixed> $run */
                $run = $ops->execute('runCosting', ['fiscalYear' => 2026, 'period' => 1]);
                $runId = $run['runId'] ?? null;
                self::assertIsString($runId);
                $ops->execute('releaseCosting', ['runId' => $runId]);

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
        // `allocate` distributes an amount by largest remainder and returns the parts —
        // pure computation, no journal effect (see the dispatcher). Nothing to log.
        // Taken from the PUBLISHED surface rather than from a list of its own. A hand-kept copy is
        // a third place to forget an operation, and it had already fallen behind by seven names:
        // the ones the dispatcher routed without publishing (F-14) were mutating, unlisted here,
        // and therefore exempt from the completeness check without anyone deciding that.
        $readOnly = ['expandTax', 'allocate'];
        $mutating = array_values(array_diff(SystemDescriptionProjection::API_OPERATIONS, $readOnly));

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
