<?php

declare(strict_types=1);

namespace Summae\Laravel;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Assets\AssetService;
use Summae\Core\Costing\CostingService;
use Summae\Core\Ledger\DimensionRegistry;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Mapping\MappingRegistry;
use Summae\Core\Partner\PartnerService;
use Summae\Core\Shared\Clock;
use Summae\Core\Shared\Currency;
use Summae\Core\Shared\IdGenerator;
use Summae\Core\Shared\SystemClock;
use Summae\Core\Shared\Uuid;
use Summae\Core\Shared\UuidV7IdGenerator;
use Summae\Core\Tax\TaxCodeRegistry;
use Summae\Core\Tax\TaxProfile;
use Summae\Core\Tax\TaxService;
use Summae\Core\Tenant;
use Summae\Laravel\Repository\EloquentAccountRepository;
use Summae\Laravel\Repository\EloquentAssetRepository;
use Summae\Laravel\Repository\EloquentAuditTrail;
use Summae\Laravel\Repository\EloquentFiscalYearRepository;
use Summae\Laravel\Repository\EloquentJournalRepository;
use Summae\Laravel\Repository\EloquentOpenItemRepository;
use Summae\Laravel\Repository\EloquentPartnerRepository;
use Summae\Laravel\Repository\EloquentVoucherRepository;

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
