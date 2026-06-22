<?php

declare(strict_types=1);

namespace Summae\Core;

use Summae\Core\Policies\Expansion\Assets\AssetService;
use Summae\Core\Policies\Expansion\Costing\CostingService;
use Summae\Core\InMemory\InMemoryAccountRepository;
use Summae\Core\InMemory\InMemoryAssetRepository;
use Summae\Core\InMemory\InMemoryAuditTrail;
use Summae\Core\InMemory\InMemoryFiscalYearRepository;
use Summae\Core\InMemory\InMemoryJournalRepository;
use Summae\Core\InMemory\InMemoryOpenItemRepository;
use Summae\Core\InMemory\InMemoryPartnerRepository;
use Summae\Core\InMemory\InMemoryVoucherRepository;
use Summae\Core\Ledger\DimensionRegistry;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\AuditTrail;
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
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;
use Summae\Core\Policies\Expansion\Tax\TaxService;

/**
 * Mandant: buchführende Einheit, oberste Datengrenze (Glossar `tenant`).
 * Bündelt Ports + Services einer Instanz. Der Laravel-Adapter ersetzt
 * die In-Memory-Ports durch die Database-Ports — der Rest bleibt gleich.
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
        public AuditTrail $audit,
        public Ledger $ledger,
        public TaxService $tax,
        public PartnerService $partnerService,
        public AssetService $assetService,
        public CostingService $costing,
        public MappingRegistry $mappings,
        public Clock $clock,
        public IdGenerator $ids,
    ) {
    }

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
    ): self {
        $clock ??= new SystemClock();
        $ids ??= new UuidV7IdGenerator($clock);
        $dimensions ??= DimensionRegistry::empty();
        $taxCodes ??= TaxCodeRegistry::empty();
        $taxProfile ??= TaxProfile::default();
        $mappings ??= MappingRegistry::empty();

        $accounts = new InMemoryAccountRepository();
        $fiscalYears = new InMemoryFiscalYearRepository();
        $vouchers = new InMemoryVoucherRepository();
        $journal = new InMemoryJournalRepository();
        $openItems = new InMemoryOpenItemRepository();
        $partners = new InMemoryPartnerRepository();
        $assets2 = new InMemoryAssetRepository();
        $audit = new InMemoryAuditTrail();

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
        );

        $tax = new TaxService($baseCurrency, $taxCodes, $taxProfile, $journal, $taxRoundingGranularity);
        $partnerService = new PartnerService($partners, $audit, $clock, $ids);
        $assetService = new AssetService($baseCurrency, $assets2, $fiscalYears, $vouchers, $ledger, $ids);
        $costing = new CostingService($baseCurrency, $accounts, $journal, $ids);

        return new self(
            $ids->next(),
            $name,
            $baseCurrency,
            $accounts,
            $fiscalYears,
            $vouchers,
            $journal,
            $openItems,
            $partners,
            $assets2,
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
