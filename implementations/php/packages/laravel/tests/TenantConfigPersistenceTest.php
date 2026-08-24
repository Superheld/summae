<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Repository\DatabaseTenantRecordRepository;

/**
 * SPEC-015: the configuration five operations change now outlives the object that changed it.
 *
 * These are the tests the finding says could not exist. A fixture builds one tenant in one process,
 * where a registry held in memory and a registry read from a table behave identically — so the
 * whole class of defect was invisible to a green conformance suite. Here the tenant is deliberately
 * thrown away and reopened, which is the only way to ask the question at all.
 *
 * Node twin: `packages/knex/test/adapter.test.ts`, "tenant configuration survives the process".
 */
final class TenantConfigPersistenceTest extends AdapterTestCase
{
    private const string TENANT_C = '0195f000-0000-7000-8000-00000000cccc';

    private const string TENANT_UNKNOWN = '0195f000-0000-7000-8000-00000000bbbb';

    public function testKeepsADimensionTypeAcrossAReopen(): void
    {
        $this->ops('Config GmbH')->execute('defineDimensionType', ['code' => 'costCenter']);

        // The next call is already the proof: declaring a VALUE of `costCenter` from a fresh tenant
        // object can only work if the TYPE declared in the first one is still known. Before this, it
        // answered E_DIMENSION_INVALID for a type the caller had just created.
        $this->ops()->execute('defineDimensionValue', ['type' => 'costCenter', 'code' => 'FERTIGUNG']);

        $record = (new DatabaseTenantRecordRepository($this->connection, Uuid::fromString(self::TENANT_C)))->load();
        self::assertNotNull($record);
        self::assertSame([['code' => 'costCenter']], $record->config['dimensionTypes']);
        self::assertSame([['typeCode' => 'costCenter', 'code' => 'FERTIGUNG']], $record->config['dimensionValues']);
    }

    public function testKeepsATaxProfileChange(): void
    {
        $this->ops('Config GmbH')->execute('setTaxProfile', [
            'smallBusiness' => ['validFrom' => '2026-01-01', 'value' => true],
        ]);

        $reopened = $this->tenantOn(Uuid::fromString(self::TENANT_C));
        self::assertTrue($reopened->tax->profile()->smallBusinessAt(CalendarDate::of('2026-06-01')));
    }

    public function testKeepsAnAllocationSchemeAndReplaysItWithoutAuditingIt(): void
    {
        $scheme = [
            'method' => 'step_ladder',
            'steps' => [['sender' => 'HILFS', 'receivers' => [['costCenter' => 'FERTIGUNG', 'share' => '1']]]],
        ];
        $first = $this->tenantOn(Uuid::fromString(self::TENANT_C), 'Config GmbH');
        (new TenantOperations($first))->execute('setAllocationScheme', $scheme);
        $auditedOnce = $this->schemeRecords($first);

        $reopened = $this->tenantOn(Uuid::fromString(self::TENANT_C));
        self::assertCount($auditedOnce, $this->rawSchemeRecords($reopened));

        // That the scheme reached the live object, and not merely the table: the audit record of the
        // NEXT change reports what it replaced, and `stepCount.from` can only be 1 if the reopened
        // service was actually carrying the stored step.
        (new TenantOperations($reopened))->execute('setAllocationScheme', ['method' => 'step_ladder', 'steps' => []]);
        $records = $this->rawSchemeRecords($reopened);
        $last = end($records);
        self::assertNotFalse($last);
        self::assertSame(['from' => 1, 'to' => 0], $last->changes['stepCount'] ?? null);
    }

    public function testNamesItsTenantSoAnUnknownIdIsNotAnEmptyLedger(): void
    {
        $this->tenantOn(Uuid::fromString(self::TENANT_C), 'Config GmbH');

        $listed = array_column(DatabaseTenantRecordRepository::listTenants($this->connection), 'name');
        self::assertContains('Config GmbH', $listed);

        $unknown = new DatabaseTenantRecordRepository($this->connection, Uuid::fromString(self::TENANT_UNKNOWN));
        self::assertNull($unknown->load());
    }

    public function testTheStoredRecordWinsOverWhatALaterOpenPassesIn(): void
    {
        $this->tenantOn(Uuid::fromString(self::TENANT_C), 'The name it was created with');

        // The seed rule: a second open passing a different name changes nothing. Two sources of
        // truth that drift is the state this finding came out of.
        $reopened = $this->tenantOn(Uuid::fromString(self::TENANT_C), 'A different name later');
        self::assertSame('The name it was created with', $reopened->name);
    }

    public function testKeepsAnImportedMapping(): void
    {
        $mapping = [
            'id' => 'gkr-bilanz',
            'kind' => 'balance-sheet',
            'nodes' => [
                ['key' => 'A', 'label' => 'Aktiva', 'side' => 'assets', 'children' => [
                    ['key' => 'A.I', 'label' => 'Umlauf', 'accounts' => [['from' => '1000', 'to' => '1999']]],
                ]],
            ],
        ];
        $this->ops('Config GmbH')->execute('importMapping', ['mapping' => $mapping]);

        $record = (new DatabaseTenantRecordRepository($this->connection, Uuid::fromString(self::TENANT_C)))->load();
        self::assertNotNull($record);
        self::assertCount(1, $record->config['mappings']);
        self::assertSame('gkr-bilanz', $record->config['mappings'][0]['id'] ?? null);

        // Same id twice replaces rather than collects: two mappings of one id would read as
        // overlapping on the next open.
        $this->ops()->execute('importMapping', ['mapping' => $mapping]);
        $again = (new DatabaseTenantRecordRepository($this->connection, Uuid::fromString(self::TENANT_C)))->load();
        self::assertNotNull($again);
        self::assertCount(1, $again->config['mappings']);
    }

    private function ops(string $name = 'Adapter GmbH'): TenantOperations
    {
        return new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_C), $name));
    }

    private function schemeRecords(\Summae\Core\Tenant $tenant): int
    {
        return count($this->rawSchemeRecords($tenant));
    }

    /** @return list<\Summae\Core\Records\AuditRecord> */
    private function rawSchemeRecords(\Summae\Core\Tenant $tenant): array
    {
        return array_values(array_filter(
            $tenant->audit->all(),
            static fn (\Summae\Core\Records\AuditRecord $record): bool => $record->objectType === 'allocationScheme',
        ));
    }
}
