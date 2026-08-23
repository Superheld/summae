<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Composition\TenantOperations;
use Summae\Core\Policies\Expansion\Settlement;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Tenant;

/**
 * IMPL-015: what goes into the database must come back out of it unchanged.
 *
 * The suites that touch this adapter today (conformance `--subject=database`, the SF-15 cross test)
 * both write and read within one process, so a field that never leaves the object graph looks
 * identical either way. Here the books are written by one tenant instance and read back by a
 * **second, freshly built** one on the same connection: everything asserted has genuinely been
 * through a column and back.
 */
final class RepositoryRoundTripTest extends AdapterTestCase
{
    private const string TENANT_ID = '0195f000-0000-7000-8000-00000000a001';

    /**
     * @return array{partnerId: string, voucherId: string, entryId: string, openItemId: string, assetId: string}
     */
    private function writeBooks(Tenant $tenant): array
    {
        $ops = new TenantOperations($tenant);

        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        foreach ([
            ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
            ['number' => '1400', 'name' => 'Forderungen', 'type' => 'asset', 'subtype' => 'ar'],
            ['number' => '0480', 'name' => 'Anlagen', 'type' => 'asset', 'subtype' => 'fixed_asset'],
            ['number' => '4830', 'name' => 'AfA', 'type' => 'expense'],
            ['number' => '4855', 'name' => 'GWG', 'type' => 'expense'],
            ['number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue'],
        ] as $account) {
            $ops->execute('createAccount', $account);
        }

        $partner = $ops->execute('createPartner', [
            'name' => 'Kunde Meier',
            'kind' => 'customer',
        ]);

        $voucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'AR-1', 'voucherDate' => '2026-03-01'],
        ]);
        $voucherId = is_string($voucher['id'] ?? null) ? $voucher['id'] : '';

        $invoice = $ops->execute('post', [
            'entryDate' => '2026-03-01',
            'voucherId' => $voucherId,
            'text' => 'Ausgangsrechnung mit Ümläuten',
            'lines' => [
                ['account' => '1400', 'side' => 'debit', 'money' => ['amount' => '1190.00', 'currency' => 'EUR']],
                ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '1190.00', 'currency' => 'EUR']],
            ],
        ]);

        /** @var list<array<string, mixed>> $created */
        $created = is_array($invoice['openItemsCreated'] ?? null) ? $invoice['openItemsCreated'] : [];
        $openItemId = is_string($created[0]['id'] ?? null) ? $created[0]['id'] : '';

        $payVoucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'ZE-1', 'voucherDate' => '2026-03-15'],
        ]);
        $payment = $ops->execute('post', [
            'entryDate' => '2026-03-15',
            'voucherId' => is_string($payVoucher['id'] ?? null) ? $payVoucher['id'] : '',
            'text' => 'Teilzahlung',
            'lines' => [
                ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '500.00', 'currency' => 'EUR']],
                ['account' => '1400', 'side' => 'credit', 'money' => ['amount' => '500.00', 'currency' => 'EUR']],
            ],
        ]);

        $ops->execute('settle', [
            'entryId' => is_string($payment['id'] ?? null) ? $payment['id'] : '',
            'allocations' => [['openItemId' => $openItemId, 'money' => ['amount' => '500.00', 'currency' => 'EUR']]],
        ]);

        $ops->execute('finalize', ['entryId' => is_string($invoice['id'] ?? null) ? $invoice['id'] : '']);

        $tenant->assetService->setRuleModule([
            'gwgThresholds' => [[
                'validFrom' => '2018-01-01', 'validTo' => null, 'immediateMax' => '800.00',
                'poolMin' => '250.01', 'poolMax' => '1000.00', 'poolYears' => 5,
            ]],
            'usefulLife' => [['assetClass' => 'it-hardware', 'months' => 36]],
            'assetAccounts' => [
                'acquisitionCounterAccount' => '1200',
                'depreciationExpenseAccount' => '4830',
                // Since the disposal writes off the carrying amount (F-AST-004), a disposal that
                // ends below book value needs somewhere to put the loss — this test disposes
                // without proceeds, so it goes through that path.
                'disposalLossAccount' => '4855',
                'disposalProceedsAccount' => '4855',
                'gwgExpenseAccount' => '4855',
            ],
        ]);
        $assetVoucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'ER-1', 'voucherDate' => '2026-04-01'],
        ]);
        $asset = $ops->execute('acquireAsset', [
            'name' => 'Laptop',
            'assetClass' => 'it-hardware',
            'assetAccount' => '0480',
            'acquisitionCost' => ['amount' => '3000.00', 'currency' => 'EUR'],
            'acquiredOn' => '2026-04-01',
            'voucherId' => is_string($assetVoucher['id'] ?? null) ? $assetVoucher['id'] : '',
            'gwgChoice' => 'auto',
        ]);
        $ops->execute('runDepreciation', ['fiscalYear' => 2026]);

        return [
            'partnerId' => is_string($partner['id'] ?? null) ? $partner['id'] : '',
            'voucherId' => $voucherId,
            'entryId' => is_string($invoice['id'] ?? null) ? $invoice['id'] : '',
            'openItemId' => $openItemId,
            'assetId' => is_string($asset['id'] ?? null) ? $asset['id'] : '',
        ];
    }

    public function testEveryAggregateSurvivesAWriteAndAFreshRead(): void
    {
        $tenantId = Uuid::fromString(self::TENANT_ID);

        $writer = $this->tenantOn($tenantId);
        $ids = $this->writeBooks($writer);

        // Second instance, same connection: nothing below can come from in-memory state.
        $reader = $this->tenantOn($tenantId);

        $encode = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR);

        self::assertSame(
            $encode($writer->accounts->all()),
            $encode($reader->accounts->all()),
            'chart of accounts',
        );
        self::assertSame(
            $encode($writer->fiscalYears->byYear(2026)),
            $encode($reader->fiscalYears->byYear(2026)),
            'fiscal year incl. its periods',
        );
        self::assertSame(
            $encode($writer->vouchers->byId(Uuid::fromString($ids['voucherId']))),
            $encode($reader->vouchers->byId(Uuid::fromString($ids['voucherId']))),
            'voucher',
        );
        self::assertSame(
            $encode($writer->journal->all()),
            $encode($reader->journal->all()),
            'journal incl. lines, status and reversal references',
        );
        self::assertSame(
            $encode($writer->openItems->all()),
            $encode($reader->openItems->all()),
            'open items incl. settlements',
        );
        self::assertSame(
            $encode($writer->partners->all()),
            $encode($reader->partners->all()),
            'partners',
        );
        self::assertSame(
            $encode($writer->assets->all()),
            $encode($reader->assets->all()),
            'assets incl. depreciation history',
        );
        self::assertSame(
            $encode($writer->audit->all()),
            $encode($reader->audit->all()),
            'audit trail',
        );

        // And the reports agree, which is what an embedding app actually sees.
        $writerOps = new TenantOperations($writer);
        $readerOps = new TenantOperations($reader);
        foreach ([
            ['trialBalance', ['fiscalYear' => 2026]],
            ['openItems', []],
            ['assetRegister', ['asOf' => '2026-12-31']],
            ['auditLog', []],
        ] as [$projection, $params]) {
            self::assertSame(
                $encode($writerOps->project($projection, $params)),
                $encode($readerOps->project($projection, $params)),
                $projection,
            );
        }
    }

    public function testNonAsciiTextAndPartialSettlementsComeBackExactly(): void
    {
        $tenantId = Uuid::fromString(self::TENANT_ID);
        $ids = $this->writeBooks($this->tenantOn($tenantId));
        $reader = $this->tenantOn($tenantId);

        $entry = $reader->journal->byId(Uuid::fromString($ids['entryId']));
        self::assertNotNull($entry);
        // Umlauts survive the JSON column: the adapter encodes unescaped UTF-8, and a mismatch here
        // would show up as Ü in the shared data format rather than as a broken read.
        self::assertSame('Ausgangsrechnung mit Ümläuten', $entry->text());
        self::assertSame('finalized', $entry->status()->value);

        $item = $reader->openItems->byId(Uuid::fromString($ids['openItemId']));
        self::assertNotNull($item);
        self::assertSame('690.00', $item->remaining()->amountAsString());
        self::assertSame('partially_settled', $item->status()->value);
        self::assertCount(1, $item->settlements());
        self::assertSame('payment', $item->settlements()[0]->cause->value);
    }

    public function testUpdatesReachTheColumnAndNotJustTheObject(): void
    {
        // `add` is exercised by every other test; the `save` paths are the ones a fixture never
        // reaches, because the conformance runner rarely changes an aggregate after creating it.
        $tenantId = Uuid::fromString(self::TENANT_ID);
        $writer = $this->tenantOn($tenantId);
        $ids = $this->writeBooks($writer);
        $ops = new TenantOperations($writer);

        $ops->execute('updatePartner', ['partnerId' => $ids['partnerId'], 'name' => 'Kunde Meier GmbH']);
        $ops->execute('disposeAsset', ['assetId' => $ids['assetId'], 'disposedOn' => '2026-11-30']);

        $reader = $this->tenantOn($tenantId);

        $partner = $reader->partners->byId(Uuid::fromString($ids['partnerId']));
        self::assertNotNull($partner);
        self::assertSame('Kunde Meier GmbH', $partner->jsonSerialize()['name'] ?? null);

        $asset = $reader->assets->byId(Uuid::fromString($ids['assetId']));
        self::assertNotNull($asset);
        self::assertSame('disposed', $asset->jsonSerialize()['status'] ?? null);
    }

    public function testTheStoredJsonIsTheAggregatesOwnSerialization(): void
    {
        // The shared data format lives in these columns — PHP writes them, Node reads them
        // (SF-15). If a repository ever encoded its own shape instead of the aggregate's, the
        // cross test would only notice for the fields it happens to compare.
        $tenantId = Uuid::fromString(self::TENANT_ID);
        $ids = $this->writeBooks($this->tenantOn($tenantId));
        $reader = $this->tenantOn($tenantId);

        $voucher = $reader->vouchers->byId(Uuid::fromString($ids['voucherId']));
        self::assertNotNull($voucher);
        self::assertSame($voucher->jsonSerialize(), $this->jsonColumn('vouchers', $ids['voucherId'], 'payload'));

        $partner = $reader->partners->byId(Uuid::fromString($ids['partnerId']));
        self::assertNotNull($partner);
        self::assertSame($partner->jsonSerialize(), $this->jsonColumn('partners', $ids['partnerId'], 'payload'));

        $item = $reader->openItems->byId(Uuid::fromString($ids['openItemId']));
        self::assertNotNull($item);
        $stored = $this->jsonColumn('open_items', $ids['openItemId'], 'settlements');
        self::assertSame(
            array_map(static fn (Settlement $s): array => $s->jsonSerialize(), $item->settlements()),
            $stored,
        );
    }
}
