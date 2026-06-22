<?php

declare(strict_types=1);

namespace Summae\Laravel;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Policies\Expansion\Assets\AssetService;
use Summae\Core\Policies\Expansion\Costing\CostingService;
use Summae\Core\Ledger\DimensionRegistry;
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
use Summae\Laravel\Repository\DatabasePartnerRepository;
use Summae\Laravel\Repository\DatabaseVoucherRepository;

/**
 * Baut einen Mandanten mit Datenbank-Persistenz — gleiche Services wie
 * Tenant::inMemory, nur die Ports zeigen auf die Datenbank. Der Kern
 * bleibt unberührt (Hexagonal, RUNTIME-LEITFADEN).
 *
 * Regelmodul-Daten (Steuerschlüssel, Profile, Mappings, Dimensionen)
 * sind versionierte Daten der App-Schicht und werden pro Instanz
 * übergeben, nicht in der Adapter-Datenbank verwaltet.
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
        );

        $tax = new TaxService($baseCurrency, $taxCodes, $taxProfile, $journal);
        $partnerService = new PartnerService($partners, $audit, $clock, $ids);
        $assetService = new AssetService($baseCurrency, $assets, $fiscalYears, $vouchers, $ledger, $ids);
        $costing = new CostingService($baseCurrency, $accounts, $journal, $ids);

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
