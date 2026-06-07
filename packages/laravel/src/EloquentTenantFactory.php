<?php

declare(strict_types=1);

namespace Rechnungswesen\Laravel;

use Illuminate\Database\ConnectionInterface;
use Rechnungswesen\Core\Assets\AssetService;
use Rechnungswesen\Core\Costing\CostingService;
use Rechnungswesen\Core\Ledger\DimensionRegistry;
use Rechnungswesen\Core\Ledger\Ledger;
use Rechnungswesen\Core\Mapping\MappingRegistry;
use Rechnungswesen\Core\Partner\PartnerService;
use Rechnungswesen\Core\Shared\Clock;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\IdGenerator;
use Rechnungswesen\Core\Shared\SystemClock;
use Rechnungswesen\Core\Shared\Uuid;
use Rechnungswesen\Core\Shared\UuidV7IdGenerator;
use Rechnungswesen\Core\Tax\TaxCodeRegistry;
use Rechnungswesen\Core\Tax\TaxProfile;
use Rechnungswesen\Core\Tax\TaxService;
use Rechnungswesen\Core\Tenant;
use Rechnungswesen\Laravel\Repository\EloquentAccountRepository;
use Rechnungswesen\Laravel\Repository\EloquentAssetRepository;
use Rechnungswesen\Laravel\Repository\EloquentAuditTrail;
use Rechnungswesen\Laravel\Repository\EloquentFiscalYearRepository;
use Rechnungswesen\Laravel\Repository\EloquentJournalRepository;
use Rechnungswesen\Laravel\Repository\EloquentOpenItemRepository;
use Rechnungswesen\Laravel\Repository\EloquentPartnerRepository;
use Rechnungswesen\Laravel\Repository\EloquentVoucherRepository;

/**
 * Baut einen Mandanten mit Eloquent-Persistenz — gleiche Services wie
 * Tenant::inMemory, nur die Ports zeigen auf die Datenbank. Der Kern
 * bleibt unberührt (Hexagonal, AGENT-BRIEFING).
 *
 * Regelmodul-Daten (Steuerschlüssel, Profile, Mappings, Dimensionen)
 * sind versionierte Daten der App-Schicht und werden pro Instanz
 * übergeben, nicht in der Adapter-Datenbank verwaltet.
 */
final readonly class EloquentTenantFactory
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

        $accounts = new EloquentAccountRepository($this->connection, $tenantId);
        $fiscalYears = new EloquentFiscalYearRepository($this->connection, $tenantId);
        $vouchers = new EloquentVoucherRepository($this->connection, $tenantId);
        $journal = new EloquentJournalRepository($this->connection, $tenantId);
        $openItems = new EloquentOpenItemRepository($this->connection, $tenantId);
        $partners = new EloquentPartnerRepository($this->connection, $tenantId);
        $assets = new EloquentAssetRepository($this->connection, $tenantId);
        $audit = new EloquentAuditTrail($this->connection, $tenantId);

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
        $assetService = new AssetService($baseCurrency, $assets, $accounts, $fiscalYears, $vouchers, $ledger, $ids);
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
