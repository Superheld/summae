<?php

declare(strict_types=1);

namespace Summae\Core;

use Summae\Core\Policies\Expansion\Assets\AssetService;
use Summae\Core\Policies\Expansion\ResultAppropriationService;
use Summae\Core\Policies\Projection\EntityProfileService;
use Summae\Core\Policies\Projection\LegalFormRegistry;
use Summae\Core\Policies\Expansion\Costing\CostingService;
use Summae\Core\InMemory\InMemoryAccountRepository;
use Summae\Core\InMemory\InMemoryAssetRepository;
use Summae\Core\Composition\TenantConfigStore;
use Summae\Core\Composition\TenantRecord;
use Summae\Core\InMemory\InMemoryAuditTrail;
use Summae\Core\InMemory\InMemoryFiscalYearRepository;
use Summae\Core\InMemory\InMemoryJournalRepository;
use Summae\Core\InMemory\InMemoryOpenItemRepository;
use Summae\Core\InMemory\InMemoryCostingRunRepository;
use Summae\Core\InMemory\InMemoryInventoryValuationRepository;
use Summae\Core\InMemory\InMemoryProvisionRepository;
use Summae\Core\InMemory\InMemoryPartnerRepository;
use Summae\Core\InMemory\InMemoryTenantRecordRepository;
use Summae\Core\InMemory\InMemoryVoucherRepository;
use Summae\Core\Policies\Constraint\AccountCombinationRegistry;
use Summae\Core\Policies\Constraint\DimensionRegistry;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Port\CostingRunRepository;
use Summae\Core\Port\InventoryValuationRepository;
use Summae\Core\Port\ProvisionRepository;
use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Partner\PartnerService;
use Summae\Core\Port\AssetRepository;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\SystemClock;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\UuidV7IdGenerator;
use Summae\Core\Policies\Expansion\Inventory\InventoryService;
use Summae\Core\Policies\Expansion\Provisions\ProvisionService;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;
use Summae\Core\Policies\Expansion\Tax\TaxService;

/**
 * Tenant: bookkeeping unit, top-most data boundary (glossary `tenant`).
 * Bundles ports + services of one instance. The Laravel adapter replaces
 * the in-memory ports with the database ports — the rest stays the same.
 */
final readonly class Tenant
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public Currency $baseCurrency,
        public AccountRepository $accounts,
        public FiscalYearRepository $fiscalYears,
        public VoucherRepository $vouchers,
        public JournalRepository $journal,
        public OpenItemRepository $openItems,
        public PartnerRepository $partners,
        public AssetRepository $assets,
        /** Reachable from the tenant like every other repository, so `costingRuns` can read it. */
        public CostingRunRepository $costingRuns,
        public AuditTrail $audit,
        public Ledger $ledger,
        public TaxService $tax,
        public PartnerService $partnerService,
        public AssetService $assetService,
        public ResultAppropriationService $resultAppropriation,
        public CostingService $costing,
        public MappingRegistry $mappings,
        public Clock $clock,
        public IdGenerator $ids,
        /**
         * Which pack this tenant was composed from — null for an inline rule bundle, where
         * there is no manifest to name. Provenance, not rules: it exists so the system
         * description can say what the books were kept under (F-IO-007).
         *
         * @var array{id: string, version: string}|null
         */
        public ?array $packIdentity = null,
        /** Where configuration changes are kept; null when this tenant has no record (SPEC-015). */
        public ?TenantConfigStore $configStore = null,
        /**
         * What the embedding declares about the identity behind `actor` (SPEC-020). Null = it has
         * not said, which `systemDescription` reports as null rather than as "no". Not stored: it
         * describes the running installation, not the books.
         *
         * @var array{declared: bool, method: string|null}|null
         */
        public ?array $actorAuthentication = null,
        /**
         * Which legal forms the pack knows and which one this tenant is (F-CORE-039). Unlike
         * `actorAuthentication` this one IS stored — it describes the entity whose books these are,
         * not the installation running them, and changing it is an audited event with a date.
         */
        public LegalFormRegistry $legalForms = new LegalFormRegistry(),
        public ?EntityProfileService $entityProfile = null,
        /**
         * Stock (F-CORE-050). Appended for the reason $combinations was — a positional parameter
         * moved to sit beside its relatives edits every call site to say what it already said — and
         * nullable for the reason $entityProfile is: both factories always pass it, so `null`
         * means somebody built a tenant by hand without stock, and the dispatcher says so instead
         * of dereferencing nothing.
         */
        public ?InventoryValuationRepository $inventoryValuations = null,
        public ?InventoryService $inventory = null,
        /** Provisions (F-CORE-051) — appended for the same reason as the two above. */
        public ?ProvisionRepository $provisions = null,
        public ?ProvisionService $provisionService = null,
    ) {
    }

    /**
     * @param array{id: string, version: string}|null            $packIdentity
     * @param array{declared: bool, method: string|null}|null     $actorAuthentication
     */
    public static function inMemory(
        string $name,
        Currency $baseCurrency,
        ?Clock $clock = null,
        ?IdGenerator $ids = null,
        ?DimensionRegistry $dimensions = null,
        ?TaxCodeRegistry $taxCodes = null,
        ?TaxProfile $taxProfile = null,
        ?MappingRegistry $mappings = null,
        string $taxRoundingGranularity = 'perVoucher',
        ?array $packIdentity = null,
        ?array $actorAuthentication = null,
        /**
         * The constraint socket's second plug (F-CORE-042). Appended rather than slotted next to
         * $dimensions, where it belongs by subject: the call sites take the defaults, and moving a
         * positional parameter to keep two related arguments adjacent would have edited all of them
         * to say what they already say.
         */
        ?AccountCombinationRegistry $combinations = null,
    ): self {
        $clock ??= new SystemClock();
        $ids ??= new UuidV7IdGenerator($clock);
        $dimensions ??= DimensionRegistry::empty();
        $combinations ??= AccountCombinationRegistry::empty();
        $taxCodes ??= TaxCodeRegistry::empty();
        $taxProfile ??= TaxProfile::default();
        $mappings ??= MappingRegistry::empty();

        $accounts = new InMemoryAccountRepository();
        $fiscalYears = new InMemoryFiscalYearRepository();
        $vouchers = new InMemoryVoucherRepository();
        $journal = new InMemoryJournalRepository();
        $openItems = new InMemoryOpenItemRepository();
        $partners = new InMemoryPartnerRepository();
        $costingRuns = new InMemoryCostingRunRepository();
        $inventoryValuations = new InMemoryInventoryValuationRepository();
        $provisions = new InMemoryProvisionRepository();
        $assets2 = new InMemoryAssetRepository();
        $audit = new InMemoryAuditTrail();

        // Drawn here rather than inline below so the services that log tenant-level
        // configuration can name it. Same position in the id sequence: nothing between
        // this line and the old call site draws an id.
        $tenantId = $ids->next();
        $auditWriter = new AuditWriter($audit, $clock, $ids);

        // An in-memory tenant gets a record too, so the four configuration operations behave the
        // same way here as they do behind a database. It buys nothing within one process — which is
        // exactly why the defect could hide from every fixture — but one code path is worth more
        // than the saving, and a core test can now prove the round trip without an adapter.
        $configStore = TenantConfigStore::open(
            new InMemoryTenantRecordRepository(),
            new TenantRecord($tenantId->value, $name, $baseCurrency->code, $packIdentity, TenantRecord::emptyConfig()),
        );

        // Built before the ledger, not with the other services below: the ledger reads the declared
        // legal form on every posting to evaluate conditional constraint rules (F-CORE-047), and it
        // must hold the same object `setEntityProfile` later writes to.
        $legalForms = new LegalFormRegistry();

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
            $configStore,
            $combinations,
            $legalForms,
            $taxProfile,
        );

        $tax = new TaxService(
            $baseCurrency,
            $taxCodes,
            $taxProfile,
            $journal,
            $taxRoundingGranularity,
            $tenantId,
            $auditWriter,
            $configStore,
        );
        $partnerService = new PartnerService($partners, $audit, $clock, $ids, $accounts, $vouchers, $openItems);
        $entityProfile = new EntityProfileService($legalForms, $auditWriter, $tenantId, $configStore);
        $assetService = new AssetService($baseCurrency, $assets2, $fiscalYears, $vouchers, $ledger, $ids, [], $tenantId, $auditWriter);
        $resultAppropriation = new ResultAppropriationService($baseCurrency, $accounts, $journal, $ledger, $auditWriter);
        $costing = new CostingService(
            $baseCurrency,
            $accounts,
            $journal,
            $costingRuns,
            $ids,
            $tenantId,
            $auditWriter,
            $configStore,
        );

        $inventory = new InventoryService(
            $baseCurrency,
            $accounts,
            $journal,
            $vouchers,
            $costingRuns,
            $inventoryValuations,
            $ledger,
            $ids,
            [],
            $tenantId,
            $auditWriter,
        );

        $provisionService = new ProvisionService(
            $baseCurrency,
            $accounts,
            $vouchers,
            $provisions,
            $ledger,
            $ids,
            [],
            $auditWriter,
        );

        return new self(
            $tenantId,
            $name,
            $baseCurrency,
            $accounts,
            $fiscalYears,
            $vouchers,
            $journal,
            $openItems,
            $partners,
            $assets2,
            $costingRuns,
            $audit,
            $ledger,
            $tax,
            $partnerService,
            $assetService,
            $resultAppropriation,
            $costing,
            $mappings,
            $clock,
            $ids,
            $packIdentity,
            $configStore,
            $actorAuthentication,
            $legalForms,
            $entityProfile,
            $inventoryValuations,
            $inventory,
            $provisions,
            $provisionService,
        );
    }
}
