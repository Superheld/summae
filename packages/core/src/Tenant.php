<?php

declare(strict_types=1);

namespace Rechnungswesen\Core;

use Rechnungswesen\Core\InMemory\InMemoryAccountRepository;
use Rechnungswesen\Core\InMemory\InMemoryAuditTrail;
use Rechnungswesen\Core\InMemory\InMemoryFiscalYearRepository;
use Rechnungswesen\Core\InMemory\InMemoryJournalRepository;
use Rechnungswesen\Core\InMemory\InMemoryOpenItemRepository;
use Rechnungswesen\Core\InMemory\InMemoryVoucherRepository;
use Rechnungswesen\Core\Ledger\DimensionRegistry;
use Rechnungswesen\Core\Ledger\Ledger;
use Rechnungswesen\Core\Port\AccountRepository;
use Rechnungswesen\Core\Port\AuditTrail;
use Rechnungswesen\Core\Port\FiscalYearRepository;
use Rechnungswesen\Core\Port\JournalRepository;
use Rechnungswesen\Core\Port\OpenItemRepository;
use Rechnungswesen\Core\Port\VoucherRepository;
use Rechnungswesen\Core\Shared\Clock;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\IdGenerator;
use Rechnungswesen\Core\Shared\SystemClock;
use Rechnungswesen\Core\Shared\Uuid;
use Rechnungswesen\Core\Shared\UuidV7IdGenerator;

/**
 * Mandant: buchführende Einheit, oberste Datengrenze (Glossar `tenant`).
 * Bündelt Ports + Services einer Instanz. Der Laravel-Adapter ersetzt
 * die In-Memory-Ports durch Eloquent (JOB-012) — der Rest bleibt gleich.
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
        public AuditTrail $audit,
        public Ledger $ledger,
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
    ): self {
        $clock ??= new SystemClock();
        $ids ??= new UuidV7IdGenerator($clock);
        $dimensions ??= DimensionRegistry::empty();

        $accounts = new InMemoryAccountRepository();
        $fiscalYears = new InMemoryFiscalYearRepository();
        $vouchers = new InMemoryVoucherRepository();
        $journal = new InMemoryJournalRepository();
        $openItems = new InMemoryOpenItemRepository();
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

        return new self(
            $ids->next(),
            $name,
            $baseCurrency,
            $accounts,
            $fiscalYears,
            $vouchers,
            $journal,
            $openItems,
            $audit,
            $ledger,
            $clock,
            $ids,
        );
    }
}
