<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\DomainError;
use Summae\Core\Ledger\OpenItem;
use Summae\Core\Ledger\PostResult;
use Summae\Core\Shared\Money;
use Summae\Core\Mapping\MappingImporter;
use Summae\Core\Projection\AccountSheetProjection;
use Summae\Core\Projection\AssetRegisterProjection;
use Summae\Core\Projection\AuditLogProjection;
use Summae\Core\Projection\BalanceSheetProjection;
use Summae\Core\Projection\CashBasisProjection;
use Summae\Core\Projection\DatevExportProjection;
use Summae\Core\Projection\EcSalesListProjection;
use Summae\Core\Projection\IncomeStatementProjection;
use Summae\Core\Projection\JournalExportProjection;
use Summae\Core\Projection\OpenItemsProjection;
use Summae\Core\Projection\TrialBalanceProjection;
use Summae\Core\Projection\VatReturnProjection;
use Summae\Core\Tenant;

/**
 * Generischer Einstieg in alle Operationen und Projektionen eines
 * Mandanten — die Schnittstelle für CLI (JOB-013, LLM-Operator) und
 * Konformitäts-Runner. Namen exakt nach api.md; Apps nutzen daneben
 * die typisierten Services direkt.
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
        $tenant = $this->tenant;
        $ledger = $tenant->ledger;

        return match ($op) {
            'expandTax' => $tenant->tax->expand($input),
            'setTaxProfile' => $this->serialize($tenant->tax->setProfile($input)),
            'postVoucher' => (new PostVoucherService($tenant))->post($input),
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
            'createFiscalYear' => [
                'year' => $ledger->createFiscalYear($input)->year,
                'periodCount' => count($tenant->fiscalYears->byYear(is_int($input['year'] ?? null) ? $input['year'] : 0)?->periods() ?? []),
            ],
            'createPartner' => $this->serialize($tenant->partnerService->create($input)),
            'updatePartner' => $this->serialize($tenant->partnerService->update($input)),
            'acquireAsset' => $tenant->assetService->acquire($input),
            'disposeAsset' => $tenant->assetService->dispose($input),
            'runDepreciation' => $tenant->assetService->runDepreciation($input),
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
            'importChartOfAccounts' => ['importedCount' => $ledger->importChartOfAccounts($input)],
            'importMapping' => (new MappingImporter($tenant->accounts, $tenant->mappings))->import($input),
            default => throw new DomainError('E_NOT_IMPLEMENTED', sprintf(
                'Operation "%s" ist nicht definiert',
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

        return match ($name) {
            'openItems' => (new OpenItemsProjection($tenant->openItems, $tenant->vouchers, $tenant->journal))
                ->compute($params),
            'trialBalance' => (new TrialBalanceProjection($tenant->baseCurrency, $tenant->accounts, $tenant->journal))
                ->compute($params),
            'accountSheet' => (new AccountSheetProjection($tenant->baseCurrency, $tenant->accounts, $tenant->journal))
                ->compute($params),
            'auditLog' => (new AuditLogProjection($tenant->audit))->compute($params),
            'assetRegister' => (new AssetRegisterProjection($tenant->assets))->compute($params),
            'costAllocationSheet' => $tenant->costing->costAllocationSheet($params),
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
                'Projektion "%s" ist nicht definiert',
                $name,
            )),
        };
    }

    /**
     * Largest-Remainder-Verteilung (Money::allocate), Skala aus Mandanten-Währung
     * (Pack-Parameter currencyScale). Reine Berechnung, kein Journal-Effekt.
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
