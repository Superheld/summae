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
class AuditTrailContractTest extends TestCase
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

    /**
     * The tenant under test — the seam that lets the SAME enumeration run against real persistence.
     *
     * The completeness check used to exist only for `Tenant::inMemory`, and that is precisely the
     * construction summae does not ship. It could therefore not see the defect class that actually
     * occurred: `DatabaseTenantFactory` took the `AuditWriter` as an OPTIONAL argument and left it
     * off for three services, so the tax profile, the asset events and the costing runs wrote no
     * record at all behind a database while every in-memory test here stayed green (0.12.0). An
     * optional dependency makes that silent — nothing fails to compile, nothing warns, the trail is
     * merely thinner in the one setup that counts.
     *
     * `AuditTrailPersistedTest` in the adapter suite overrides this and inherits every case below.
     */
    protected function buildTenant(FixedClock $clock): Tenant
    {
        return Tenant::inMemory('Audit GmbH', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));
    }

    private function freshOps(): TenantOperations
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $tenant = $this->buildTenant($clock);
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
        // The appropriation plug, for the same reason: without it the operation refuses with
        // E_APPROPRIATION_UNSUPPORTED instead of reaching the trail.
        $tenant->resultAppropriation->setRuleModule([
            'resultAppropriation' => [
                'allocationAccount' => '2300',
                'targets' => ['carryForward' => ['account' => '2100', 'label' => 'Gewinnvortrag']],
            ],
        ]);

        // And the legal-form catalogue, for the same reason: without it `setEntityProfile` refuses on
        // the empty catalogue instead of reaching the trail.
        $tenant->legalForms->setRuleModule([
            'legalForms' => [
                'sizeClasses' => ['small'],
                'forms' => [
                    'limited' => [
                        'label' => 'Limited company',
                        'resolution' => ['required' => true, 'deadlineMonths' => 8],
                    ],
                ],
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
        // The resolution is an ordinary entry, so it is the entry that is audited — which is the
        // point: an appropriation must be as traceable as any other posting, not a side channel.
        yield 'appropriateResult' => ['appropriateResult', 'journalEntry', 'created'];
        // --- tenant-level configuration (F-CORE-014 "Steuerschlüssel, Profile")
        yield 'setTaxProfile' => ['setTaxProfile', 'taxProfile', 'changed'];
        yield 'setEntityProfile' => ['setEntityProfile', 'entityProfile', 'changed'];
        yield 'importMapping' => ['importMapping', 'mapping', 'imported'];
        yield 'setAllocationScheme' => ['setAllocationScheme', 'allocationScheme', 'changed'];
        // --- partners --------------------------------------------------------
        yield 'createPartner' => ['createPartner', 'partner', 'created'];
        yield 'updatePartner' => ['updatePartner', 'partner', 'updated'];
        yield 'deactivatePartner' => ['deactivatePartner', 'partner', 'deactivated'];
        yield 'reactivatePartner' => ['reactivatePartner', 'partner', 'reactivated'];
        // The one operation whose audit record is what SURVIVES it: `erased` names the id, the
        // actor and the moment, and the records it replaced — including createPartner's, which
        // held the name — are gone. See PartnerService::erase.
        yield 'erasePartner' => ['erasePartner', 'partner', 'erased'];
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
            case 'appropriateResult':
                $voucherId = $this->seed($ops);
                $ops->execute('createAccount', ['number' => '2100', 'name' => 'Gewinnvortrag', 'type' => 'equity']);
                $ops->execute('createAccount', ['number' => '2300', 'name' => 'Ergebnisverwendung', 'type' => 'equity', 'subtype' => 'result_allocation']);
                $ops->execute('createAccount', ['number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue']);
                $ops->execute('post', [
                    'entryDate' => '2026-01-20',
                    'voucherId' => $voucherId,
                    'text' => 'Erlös',
                    'lines' => [
                        ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '900.00', 'currency' => 'EUR']],
                        ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '900.00', 'currency' => 'EUR']],
                    ],
                ]);
                $ops->execute('appropriateResult', [
                    'fiscalYear' => 2026,
                    'entryDate' => '2026-06-30',
                    'voucherId' => $voucherId,
                    'appropriations' => [['target' => 'carryForward', 'money' => ['amount' => '900.00', 'currency' => 'EUR']]],
                ]);

                return;
            case 'setTaxProfile':
                $ops->execute('setTaxProfile', ['smallBusiness' => ['validFrom' => '2026-01-01', 'value' => true]]);

                return;
            case 'setEntityProfile':
                $ops->execute('setEntityProfile', ['legalForm' => 'limited', 'sizeClass' => 'small']);

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
            case 'erasePartner':
                /** @var array<string, mixed> $partner */
                $partner = $ops->execute('createPartner', ['name' => 'Kunde AG', 'kind' => 'customer']);
                self::assertIsString($partner['id'] ?? null);
                $ops->execute('erasePartner', ['partnerId' => $partner['id']]);

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
            case 'reverseSettled':
                // Not an operation of its own: `openItem/cancelled` is what `reverse` leaves behind
                // when the entry it undoes had created an open item. Published as an audited event
                // and, until this recipe existed, never observed by any test.
                $voucherId = $this->seed($ops);
                $ops->execute('createAccount', ['number' => '1400', 'name' => 'Forderungen', 'type' => 'asset', 'subtype' => 'ar']);
                $ops->execute('createAccount', ['number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue']);
                /** @var array<string, mixed> $invoice */
                $invoice = $ops->execute('post', [
                    'entryDate' => '2026-01-20',
                    'voucherId' => $voucherId,
                    'text' => 'Rechnung',
                    'lines' => [
                        ['account' => '1400', 'side' => 'debit', 'money' => ['amount' => '119.00', 'currency' => 'EUR']],
                        ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '119.00', 'currency' => 'EUR']],
                    ],
                ]);
                $invoiceId = $invoice['id'] ?? null;
                self::assertIsString($invoiceId);
                $ops->execute('reverse', ['entryId' => $invoiceId, 'entryDate' => '2026-01-26', 'text' => 'Storno']);

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

    /**
     * Every audit record every recipe produces, from a fresh tenant each time.
     *
     * `reverseSettled` is in the list and is not an operation: `openItem/cancelled` is what
     * `reverse` leaves behind when the entry it undoes had created an open item. It is a published
     * audited event that no case observed, which is exactly the kind of hole the two tests below
     * are for.
     *
     * @return list<array<string, mixed>>
     */
    private function allObservedRecords(): array
    {
        $recipes = ['reverseSettled'];
        foreach (self::auditedOperations() as $case) {
            $recipes[] = $case[0];
        }

        $records = [];
        foreach (array_unique($recipes) as $recipe) {
            $ops = $this->freshOps();
            $this->runOperation($ops, $recipe);
            $records = [...$records, ...$this->auditRecords($ops)];
        }

        return $records;
    }

    /** @param array<string, mixed> $record */
    private static function pairOf(array $record): string
    {
        $objectType = is_string($record['objectType'] ?? null) ? $record['objectType'] : '';
        $action = is_string($record['action'] ?? null) ? $record['action'] : '';

        return $objectType . '/' . $action;
    }

    /**
     * The published event list is what an auditor reads as "this is what the trail records".
     * Nothing held it against reality, and it had already fallen behind once (0.11.0). Both
     * directions, like `capabilities`: under-reporting hides a recorded event, over-reporting
     * promises one that never appears — and a Verfahrensdokumentation that claims more than the
     * software does is worse than one that claims less.
     */
    public function testPublishedEventListMatchesWhatIsActuallyRecorded(): void
    {
        $published = [];
        foreach (SystemDescriptionProjection::AUDITED_EVENTS as $event) {
            foreach ($event['actions'] as $action) {
                $published[] = $event['objectType'] . '/' . $action;
            }
        }

        $observed = [];
        foreach ($this->allObservedRecords() as $record) {
            $observed[] = self::pairOf($record);
        }

        $published = array_unique($published);
        $observed = array_unique($observed);
        sort($published);
        sort($observed);

        self::assertSame(
            [],
            array_values(array_diff($observed, $published)),
            'the trail writes these events and systemDescription does not publish them — '
            .'a description that under-reports lets a recorded event look like one that never happens',
        );
        self::assertSame(
            [],
            array_values(array_diff($published, $observed)),
            'systemDescription publishes these events and no operation produces them — '
            .'either the claim is stale or a recipe is missing above; both are findings',
        );
    }

    /**
     * The published invariant says a record carries "actor, timestamp, object and before/after
     * values". It was true of most records and not of all: accounts, postings and partners wrote an
     * empty diff while vouchers, fiscal years, dimensions and costing runs wrote `from: null`.
     * A creation is a change from nothing, and writing it as nothing is what made the claim false.
     */
    public function testEveryRecordCarriesABeforeAfterDiff(): void
    {
        $empty = [];
        foreach ($this->allObservedRecords() as $record) {
            $changes = $record['changes'] ?? null;
            if (!is_array($changes) || $changes === []) {
                $empty[] = self::pairOf($record);
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($empty)),
            'these events record no before/after values, which systemDescription promises they do',
        );
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
