<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\DomainError;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Policies\Projection\AccountSheetProjection;
use Summae\Core\Policies\Projection\AssetRegisterProjection;
use Summae\Core\Policies\Projection\AuditDataExportProjection;
use Summae\Core\Policies\Projection\AuditLogProjection;
use Summae\Core\Policies\Projection\BalanceSheetProjection;
use Summae\Core\Policies\Projection\CashBasisProjection;
use Summae\Core\Policies\Projection\CashJournalProjection;
use Summae\Core\Policies\Projection\DatevExportProjection;
use Summae\Core\Policies\Projection\EcSalesListProjection;
use Summae\Core\Policies\Projection\IncomeStatementProjection;
use Summae\Core\Policies\Projection\JournalExportProjection;
use Summae\Core\Policies\Projection\Mapping\MappingImporter;
use Summae\Core\Policies\Projection\AccountsProjection;
use Summae\Core\Policies\Projection\CostingRunsProjection;
use Summae\Core\Policies\Projection\JournalProjection;
use Summae\Core\Policies\Projection\FiscalYearsProjection;
use Summae\Core\Policies\Projection\OpenItemsProjection;
use Summae\Core\Policies\Projection\SystemDescriptionProjection;
use Summae\Core\Policies\Projection\TenantConfigurationProjection;
use Summae\Core\Policies\Projection\TrialBalanceProjection;
use Summae\Core\Policies\Projection\UnfinalizedEntriesProjection;
use Summae\Core\Policies\Projection\VatReturnProjection;
use Summae\Core\Records\OpenItem;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PostResult;
use Summae\Core\Tenant;

/**
 * Generic entry into all operations and projections of a
 * tenant — the interface for CLI (JOB-013, LLM operator) and
 * conformance runner. Names exactly per api.md; apps additionally use
 * the typed services directly.
 */
final readonly class TenantOperations
{
    public function __construct(
        private Tenant $tenant,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function execute(string $op, array $input): array
    {
        // The input contract is checked here, before routing: one place instead of one check per
        // handler, and the same place in both languages — the mirror of what `project()` does
        // below. An operation below therefore reads inputs that are either absent or of the
        // declared type.
        OperationParameters::validate($op, $input);

        $tenant = $this->tenant;
        $ledger = $tenant->ledger;

        return match ($op) {
            'expandTax' => $tenant->tax->expand($input),
            'setTaxProfile' => $this->serialize($tenant->tax->setProfile($input)),
            'postVoucher' => (new PostVoucherService($tenant))->post($input),
            'createVoucher' => (new PostVoucherService($tenant))->createVoucher($input),
            'post' => $this->postResult($ledger->post($input)),
            'correct' => $this->serialize($ledger->correct($input)),
            'finalize' => ['finalizedCount' => $ledger->finalize($input)],
            'reverse' => $this->serialize($ledger->reverse($input)),
            'settle' => [
                'openItems' => array_map($this->serialize(...), $ledger->settle($input)),
            ],
            'closePeriod' => $this->periodResult($input, $ledger->closePeriod($input)->status()->value),
            'reopenPeriod' => $this->periodResult($input, $ledger->reopenPeriod($input)->status()->value),
            'closeFiscalYear' => [
                'fiscalYear' => $ledger->closeFiscalYear($input)->year,
                'status' => 'closed',
            ],
            'createAccount' => $this->serialize($ledger->createAccount($input)),
            'defineDimensionType' => $ledger->defineDimensionType($input),
            'defineDimensionValue' => $ledger->defineDimensionValue($input),
            'createFiscalYear' => [
                'year' => $ledger->createFiscalYear($input)->year,
                'periodCount' => count($tenant->fiscalYears->byYear(is_int($input['year'] ?? null) ? $input['year'] : 0)?->periods() ?? []),
            ],
            'createPartner' => $this->serialize($tenant->partnerService->create($input)),
            'deactivatePartner' => $this->serialize($tenant->partnerService->setStatus($input, 'inactive')),
            'reactivatePartner' => $this->serialize($tenant->partnerService->setStatus($input, 'active')),
            'updatePartner' => $this->serialize($tenant->partnerService->update($input)),
            'appropriateResult' => $tenant->resultAppropriation->appropriate($input),
            'acquireAsset' => $tenant->assetService->acquire($input),
            'disposeAsset' => $tenant->assetService->dispose($input),
            'runDepreciation' => $tenant->assetService->runDepreciation($input),
            'writeDownAsset' => $tenant->assetService->writeDownAsset($input),
            'bookSpecialDepreciation' => $tenant->assetService->bookSpecialDepreciation($input),
            'reportAssetUsage' => $tenant->assetService->reportAssetUsage($input),
            'allocate' => $this->allocate($input),
            'setAllocationScheme' => $tenant->costing->setAllocationScheme($input),
            'runCosting' => [
                'runId' => ($run = $tenant->costing->run($input))->id->value,
                'status' => $run->status(),
                'version' => $run->version,
            ],
            'releaseCosting' => [
                'runId' => ($released = $tenant->costing->release($input))->id->value,
                'status' => $released->status(),
            ],
            'lockAccount' => $this->serialize($ledger->lockAccount($input)),
            'unlockAccount' => $this->serialize($ledger->unlockAccount($input)),
            'importChartOfAccounts' => ['importedCount' => $ledger->importChartOfAccounts($input)],
            'importMapping' => (new MappingImporter(
                $tenant->accounts,
                $tenant->mappings,
                $tenant->id,
                new AuditWriter($tenant->audit, $tenant->clock, $tenant->ids),
                $tenant->configStore,
            ))->import($input),
            default => throw new DomainError('E_NOT_IMPLEMENTED', sprintf(
                'Operation "%s" is not defined',
                $op,
            )),
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function project(string $name, array $params): array
    {
        $tenant = $this->tenant;

        // The parameter contract is checked here, before routing: one place instead of one check
        // per projection, and the same place in both languages. Projections below therefore read
        // parameters that are either absent or of the declared type.
        ProjectionParameters::validate($name, $params);

        return match ($name) {
            'accounts' => (new AccountsProjection($tenant->accounts))->compute($params),
            'journal' => (new JournalProjection($tenant->accounts, $tenant->journal, $tenant->vouchers, $tenant->audit))
                ->compute($params),
            'fiscalYears' => (new FiscalYearsProjection($tenant->fiscalYears))->compute($params),
            'openItems' => (new OpenItemsProjection($tenant->openItems, $tenant->vouchers, $tenant->journal, $tenant->partners))
                ->compute($params),
            'trialBalance' => (new TrialBalanceProjection($tenant->baseCurrency, $tenant->accounts, $tenant->journal))
                ->compute($params),
            'accountSheet' => (new AccountSheetProjection($tenant->baseCurrency, $tenant->accounts, $tenant->journal))
                ->compute($params),
            'auditLog' => (new AuditLogProjection($tenant->audit))->compute($params),
            'unfinalizedEntries' => (new UnfinalizedEntriesProjection($tenant->journal, $tenant->clock, $tenant->audit))->compute($params),
            'systemDescription' => (new SystemDescriptionProjection(
                $tenant->id,
                $tenant->name,
                $tenant->baseCurrency,
                $tenant->packIdentity,
                $tenant->tax->profile()->jsonSerialize(),
                $tenant->actorAuthentication,
            ))->compute($params),
            'tenantConfiguration' => (new TenantConfigurationProjection(
                $tenant->tax->profile()->jsonSerialize(),
                $tenant->ledger->dimensionRegistry(),
                $tenant->costing->allocationScheme(),
                $tenant->mappings,
                $tenant->resultAppropriation->offeredTargets(),
            ))->compute($params),
            'cashJournal' => (new CashJournalProjection($tenant->baseCurrency, $tenant->accounts, $tenant->journal))->compute($params),
            'assetRegister' => (new AssetRegisterProjection($tenant->assets))->compute($params),
            'costingRuns' => (new CostingRunsProjection($tenant->costingRuns))->compute($params),
            'costAllocationSheet' => $tenant->costing->costAllocationSheet($params),
            'overheadRates' => $tenant->costing->overheadRates($params),
            'productionCost' => $tenant->costing->productionCost($params),
            'journalExport' => (new JournalExportProjection(
                $tenant->id,
                $tenant->name,
                $tenant->baseCurrency,
                $tenant->journal,
                $tenant->accounts,
                $tenant->vouchers,
                $tenant->partners,
                $tenant->audit,
                $tenant->clock,
            ))->compute($params),
            'datevExport' => (new DatevExportProjection(
                $tenant->journal,
                $tenant->accounts,
                $tenant->vouchers,
                $tenant->partners,
                $tenant->tax->registry(),
            ))->compute($params),
            'auditDataExport' => (new AuditDataExportProjection(
                $tenant->baseCurrency,
                $tenant->journal,
                $tenant->accounts,
                $tenant->vouchers,
            ))->compute($params),
            'incomeStatement' => (new IncomeStatementProjection(
                $tenant->baseCurrency,
                $tenant->accounts,
                $tenant->journal,
                $tenant->mappings,
            ))->compute($params),
            'balanceSheet' => (new BalanceSheetProjection(
                $tenant->baseCurrency,
                $tenant->accounts,
                $tenant->journal,
                $tenant->mappings,
            ))->compute($params),
            'vatReturn' => (new VatReturnProjection(
                $tenant->baseCurrency,
                $tenant->journal,
                $tenant->openItems,
                $tenant->vouchers,
                $tenant->accounts,
                $tenant->tax->registry(),
                $tenant->tax->profile(),
            ))->compute($params),
            'ecSalesList' => (new EcSalesListProjection(
                $tenant->journal,
                $tenant->vouchers,
                $tenant->partners,
                $tenant->tax->registry(),
            ))->compute($params),
            'cashBasisReport' => (new CashBasisProjection(
                $tenant->baseCurrency,
                $tenant->accounts,
                $tenant->journal,
                $tenant->openItems,
                $tenant->vouchers,
                $tenant->fiscalYears,
                $tenant->mappings,
            ))->compute($params),
            default => throw new DomainError('E_NOT_IMPLEMENTED', sprintf(
                'Projection "%s" is not defined',
                $name,
            )),
        };
    }

    /**
     * Largest-remainder distribution (Money::allocate), scale from the tenant currency
     * (pack parameter currencyScale). Pure computation, no journal effect.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function allocate(array $input): array
    {
        $totalRaw = is_array($input['total'] ?? null) ? $input['total'] : [];
        $amount = is_string($totalRaw['amount'] ?? null) ? $totalRaw['amount'] : '';
        $total = Money::of($amount, $this->tenant->baseCurrency);
        /** @var list<int|string> $weights */
        $weights = is_array($input['weights'] ?? null) ? array_values($input['weights']) : [];
        $parts = $total->allocate(...$weights);

        return [
            'parts' => array_map(static fn (Money $part): array => $part->jsonSerialize(), $parts),
            'total' => $total->jsonSerialize(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postResult(PostResult $result): array
    {
        return $this->serialize($result->entry) + [
            'openItemsCreated' => array_map(
                fn (OpenItem $item): array => $this->serialize($item),
                $result->openItemsCreated,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function periodResult(array $input, string $status): array
    {
        return [
            'fiscalYear' => $input['fiscalYear'] ?? null,
            'period' => $input['period'] ?? null,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(\JsonSerializable $object): array
    {
        $json = json_encode($object, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
