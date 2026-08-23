<?php

declare(strict_types=1);

namespace Summae\Laravel;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Policies\Expansion\Assets\AssetService;
use Summae\Core\Policies\Expansion\Costing\CostingService;
use Summae\Core\Policies\Constraint\DimensionRegistry;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;
use Summae\Core\Partner\PartnerService;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\SystemClock;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\UuidV7IdGenerator;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;
use Summae\Core\Policies\Expansion\Tax\TaxService;
use Summae\Core\Tenant;
use Summae\Laravel\Repository\DatabaseAccountRepository;
use Summae\Laravel\Repository\DatabaseAssetRepository;
use Summae\Laravel\Repository\DatabaseAuditTrail;
use Summae\Laravel\Repository\DatabaseFiscalYearRepository;
use Summae\Laravel\Repository\DatabaseJournalRepository;
use Summae\Laravel\Repository\DatabaseOpenItemRepository;
use Summae\Laravel\Repository\DatabaseCostingRunRepository;
use Summae\Laravel\Repository\DatabasePartnerRepository;
use Summae\Laravel\Repository\DatabaseVoucherRepository;

/**
 * Builds a tenant with database persistence — same services as
 * Tenant::inMemory, only the ports point at the database. The core
 * stays untouched (hexagonal, RUNTIME-LEITFADEN).
 *
 * Pack data (tax codes, profiles, mappings, dimensions) are
 * versioned data of the app layer and are passed per instance,
 * not managed in the adapter database.
 */
final readonly class DatabaseTenantFactory
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    public function build(
        string $name,
        Currency $baseCurrency,
        ?Clock $clock = null,
        ?IdGenerator $ids = null,
        ?DimensionRegistry $dimensions = null,
        ?TaxCodeRegistry $taxCodes = null,
        ?TaxProfile $taxProfile = null,
        ?MappingRegistry $mappings = null,
        ?Uuid $tenantId = null,
        // The in-memory tenant takes this from the pack's policy; here it was not even accepted, so
        // a pack asking for per-line rounding got per-voucher rounding as soon as it ran against a
        // database — the two setups would have computed different tax from the same input, which is
        // the one thing this project promises never happens. Default unchanged, so nothing moves for
        // a caller who does not pass it.
        string $taxRoundingGranularity = 'perVoucher',
    ): Tenant {
        $clock ??= new SystemClock();
        $ids ??= new UuidV7IdGenerator($clock);
        $dimensions ??= DimensionRegistry::empty();
        $taxCodes ??= TaxCodeRegistry::empty();
        $taxProfile ??= TaxProfile::default();
        $mappings ??= MappingRegistry::empty();

        $tenantId ??= $ids->next();

        $accounts = new DatabaseAccountRepository($this->connection, $tenantId);
        $fiscalYears = new DatabaseFiscalYearRepository($this->connection, $tenantId);
        $vouchers = new DatabaseVoucherRepository($this->connection, $tenantId);
        $journal = new DatabaseJournalRepository($this->connection, $tenantId);
        $openItems = new DatabaseOpenItemRepository($this->connection, $tenantId);
        $partners = new DatabasePartnerRepository($this->connection, $tenantId);
        $costingRuns = new DatabaseCostingRunRepository($this->connection, $tenantId);
        $assets = new DatabaseAssetRepository($this->connection, $tenantId);
        $audit = new DatabaseAuditTrail($this->connection, $tenantId);

        $ledger = new Ledger(
            $baseCurrency,
            $accounts,
            $fiscalYears,
            $vouchers,
            $journal,
            $openItems,
            $audit,
            $dimensions,
            $clock,
            $ids,
            $taxCodes,
            $tenantId,
        );

        // The same writer the ledger uses. Three services take it as an OPTIONAL argument, and this
        // factory used to leave all three off — so with real persistence the tax profile, the asset
        // events and the costing runs wrote no audit record at all, while the in-memory tenant wrote
        // them and every in-memory test stayed green. Optional arguments made it silent: nothing
        // failed to compile, nothing warned, and the trail was simply thinner in the one setup that
        // matters. Adding a service here means passing these through.
        $auditWriter = new AuditWriter($audit, $clock, $ids);

        $tax = new TaxService($baseCurrency, $taxCodes, $taxProfile, $journal, $taxRoundingGranularity, $tenantId, $auditWriter);
        $partnerService = new PartnerService($partners, $audit, $clock, $ids);
        $assetService = new AssetService($baseCurrency, $assets, $fiscalYears, $vouchers, $ledger, $ids, [], $tenantId, $auditWriter);
        $costing = new CostingService($baseCurrency, $accounts, $journal, $costingRuns, $ids, $tenantId, $auditWriter);

        return new Tenant(
            $tenantId,
            $name,
            $baseCurrency,
            $accounts,
            $fiscalYears,
            $vouchers,
            $journal,
            $openItems,
            $partners,
            $assets,
            $audit,
            $ledger,
            $tax,
            $partnerService,
            $assetService,
            $costing,
            $mappings,
            $clock,
            $ids,
        );
    }
}
