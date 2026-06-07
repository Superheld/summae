<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner\Subject;

use Rechnungswesen\Core\Composition\TenantFactory;
use Rechnungswesen\Core\Composition\TenantOperations;
use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Ledger\Account;
use Rechnungswesen\Core\Ledger\AccountStatus;
use Rechnungswesen\Core\Ledger\AccountType;
use Rechnungswesen\Core\Ledger\DimensionRegistry;
use Rechnungswesen\Core\Ledger\FiscalYear;
use Rechnungswesen\Core\Ledger\Voucher;
use Rechnungswesen\Core\Mapping\MappingRegistry;
use Rechnungswesen\Core\Shared\AccountNumber;
use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\FixedClock;
use Rechnungswesen\Core\Shared\Uuid;
use Rechnungswesen\Core\Tax\TaxCodeRegistry;
use Rechnungswesen\Core\Tax\TaxProfile;
use Rechnungswesen\Core\Shared\DeterministicIdGenerator;
use Rechnungswesen\Core\Tenant;

/**
 * Subject über rechnungswesen/core mit In-Memory-Ports.
 *
 * Feste Uhr: recordedAt-Zeitstempel sind damit über beide Suite-Läufe
 * identisch — der Doppellauf-Determinismus-Check vergleicht Spuren
 * nach UUID-Normalisierung, Zeitstempel müssen selbst stabil sein.
 *
 * Stand JOB-003: Ledger-Operationen. Tax/Assets/Costing/Projektionen
 * folgen mit ihren Jobs und melden bis dahin E_NOT_IMPLEMENTED.
 */
final class CoreSubject implements Subject
{
    private const string FIXED_NOW = '2026-06-07T12:00:00+02:00';

    /** @var \Closure(string, mixed...): Tenant|null Adapter-Hook: baut den Mandanten (Default: In-Memory) */
    private readonly ?\Closure $tenantBuilder;

    public function __construct(?\Closure $tenantBuilder = null)
    {
        $this->tenantBuilder = $tenantBuilder;
    }

    private ?Tenant $tenant = null;

    /** @var array<string, Tenant> per createTenant angelegte Mandanten */
    private array $tenants = [];

    /** @var array<string, mixed> Regelmodul-Daten für createTenant */
    private array $ruleModules = [];

    public function setup(array $setup): void
    {
        $tenantData = is_array($setup['tenant'] ?? null) ? $setup['tenant'] : [];
        $name = is_string($tenantData['name'] ?? null) ? $tenantData['name'] : 'Fixture';
        $currency = Currency::of(
            is_string($tenantData['baseCurrency'] ?? null) ? $tenantData['baseCurrency'] : 'EUR',
        );

        $clock = FixedClock::at(self::FIXED_NOW);

        /** @var array<string, mixed> $ruleModules */
        $ruleModules = is_array($setup['ruleModules'] ?? null) ? $setup['ruleModules'] : [];
        // Manche Fixtures nutzen den Singular-Schlüssel `ruleModule`.
        if (is_array($setup['ruleModule'] ?? null)) {
            /** @var array<string, mixed> $ruleModules */
            $ruleModules = [...$ruleModules, ...$setup['ruleModule']];
        }
        $this->ruleModules = $ruleModules;

        if (!is_array($setup['tenant'] ?? null)) {
            // Kein Setup-Mandant (createTenant-Fixtures): nur Regelmodule
            // halten; Mandanten entstehen über die Operation.
            $this->tenant = null;

            return;
        }

        // taxCodes: top-level oder als Regelmodul; taxProfile: top-level oder am Tenant
        /** @var list<array<mixed>> $taxCodeData */
        $taxCodeData = array_values(array_filter(
            is_array($setup['taxCodes'] ?? null)
                ? $setup['taxCodes']
                : (is_array($ruleModules['taxCodes'] ?? null) ? $ruleModules['taxCodes'] : []),
            is_array(...),
        ));

        $taxProfileData = is_array($setup['taxProfile'] ?? null)
            ? $setup['taxProfile']
            : (is_array($tenantData['taxProfile'] ?? null) ? $tenantData['taxProfile'] : []);

        /** @var list<array{code: string}> $dimensionTypes */
        $dimensionTypes = is_array($setup['dimensionTypes'] ?? null) ? array_values($setup['dimensionTypes']) : [];
        /** @var list<array{typeCode: string, code: string}> $dimensionValues */
        $dimensionValues = is_array($setup['dimensionValues'] ?? null) ? array_values($setup['dimensionValues']) : [];
        /** @var list<array{accountRange: array{from: string, to: string}, requiredDimension: string}> $dimensionRules */
        $dimensionRules = is_array($ruleModules['dimensionRules'] ?? null) ? array_values($ruleModules['dimensionRules']) : [];

        $builder = $this->tenantBuilder ?? static function (string $n, mixed ...$args): Tenant {
            /** @phpstan-ignore-next-line Builder-Vertrag: Argumente entsprechen Tenant::inMemory */
            return Tenant::inMemory($n, ...$args);
        };
        /** @var Tenant $tenant */
        $tenant = $builder(
            $name,
            $currency,
            $clock,
            new DeterministicIdGenerator($clock),
            DimensionRegistry::fromData($dimensionTypes, $dimensionValues, $dimensionRules),
            TaxCodeRegistry::fromData($taxCodeData),
            TaxProfile::fromData($taxProfileData),
            MappingRegistry::fromRuleModules(
                is_array($ruleModules['mappings'] ?? null) ? array_values($ruleModules['mappings']) : [],
            ),
        );

        foreach (is_array($setup['accounts'] ?? null) ? $setup['accounts'] : [] as $accountData) {
            if (!is_array($accountData)) {
                continue;
            }

            $tenant->accounts->add($this->buildAccount($tenant, $accountData));
        }

        foreach (is_array($setup['fiscalYears'] ?? null) ? $setup['fiscalYears'] : [] as $fiscalYearData) {
            if (!is_array($fiscalYearData)) {
                continue;
            }

            $tenant->fiscalYears->add($this->buildFiscalYear($tenant, $fiscalYearData));
        }

        foreach (is_array($setup['vouchers'] ?? null) ? $setup['vouchers'] : [] as $voucherData) {
            if (!is_array($voucherData)) {
                continue;
            }

            $tenant->vouchers->add($this->buildVoucher($voucherData));
        }

        $tenant->assetService->setRuleModule($ruleModules);

        $this->tenant = $tenant;
    }

    public function execute(string $op, array $input): array
    {
        // createTenant braucht keinen bestehenden Mandanten.
        if ($op === 'createTenant') {
            try {
                return $this->createTenant($input);
            } catch (DomainError $e) {
                throw new SubjectError($e->errorCode, $e->getMessage());
            }
        }

        $tenant = $this->resolveTenant($input);
        unset($input['tenant']);

        try {
            return (new TenantOperations($tenant))->execute($op, $input);
        } catch (DomainError $e) {
            throw new SubjectError($e->errorCode, $e->getMessage());
        }
    }

    public function project(string $name, array $params): array
    {
        $tenant = $this->resolveTenant($params);
        unset($params['tenant']);

        try {
            return (new TenantOperations($tenant))->project($name, $params);
        } catch (DomainError $e) {
            throw new SubjectError($e->errorCode, $e->getMessage());
        }
    }

    /**
     * Mandanten-Routing: explizite tenant-Referenz (createTenant-Fixtures)
     * oder der Setup-Mandant; existiert keiner, der zuletzt angelegte.
     *
     * @param array<string, mixed> $input
     */
    private function resolveTenant(array $input): Tenant
    {
        $ref = $input['tenant'] ?? null;

        if (is_string($ref) && isset($this->tenants[$ref])) {
            return $this->tenants[$ref];
        }

        if ($this->tenant !== null) {
            return $this->tenant;
        }

        $last = end($this->tenants);

        return $last !== false ? $last : throw new \LogicException('Kein Mandant vorhanden');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function createTenant(array $input): array
    {
        $clock = FixedClock::at(self::FIXED_NOW);
        $factory = new TenantFactory($this->ruleModules, $clock, new DeterministicIdGenerator($clock));
        $created = $factory->create($input);

        $this->tenants[$created['tenant']->id->value] = $created['tenant'];

        return $created['result'];
    }

    /**
     * @param array<mixed> $data
     */
    private function buildAccount(Tenant $tenant, array $data): Account
    {
        $number = is_string($data['number'] ?? null) ? $data['number'] : '';
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $type = AccountType::from(is_string($data['type'] ?? null) ? $data['type'] : '');
        $subtype = is_string($data['subtype'] ?? null) ? $data['subtype'] : null;
        $status = ($data['status'] ?? null) === 'locked' ? AccountStatus::Locked : AccountStatus::Active;

        return new Account($tenant->ids->next(), AccountNumber::of($number), $name, $type, $subtype, $status);
    }

    /**
     * @param array<mixed> $data
     */
    private function buildFiscalYear(Tenant $tenant, array $data): FiscalYear
    {
        $year = is_int($data['year'] ?? null) ? $data['year'] : 0;
        $start = CalendarDate::of(is_string($data['start'] ?? null) ? $data['start'] : '');
        $end = CalendarDate::of(is_string($data['end'] ?? null) ? $data['end'] : '');

        $explicitPeriods = null;
        if (is_array($data['periods'] ?? null)) {
            $explicitPeriods = [];
            foreach (array_values($data['periods']) as $periodData) {
                if (!is_array($periodData)) {
                    continue;
                }

                $explicitPeriods[] = [
                    'period' => is_int($periodData['period'] ?? null) ? $periodData['period'] : 0,
                    'start' => CalendarDate::of(is_string($periodData['start'] ?? null) ? $periodData['start'] : ''),
                    'end' => CalendarDate::of(is_string($periodData['end'] ?? null) ? $periodData['end'] : ''),
                ];
            }
        }

        return FiscalYear::create($tenant->ids->next(), $year, $start, $end, $explicitPeriods);
    }

    /**
     * @param array<mixed> $data
     */
    private function buildVoucher(array $data): Voucher
    {
        // Platzhalter-IDs hat der Runner bereits durch UUIDs ersetzt.
        $id = Uuid::fromString(is_string($data['id'] ?? null) ? $data['id'] : Uuid::v7()->value);

        $servicePeriod = is_array($data['servicePeriod'] ?? null) ? $data['servicePeriod'] : [];

        return new Voucher(
            $id,
            is_string($data['voucherNumber'] ?? null) ? $data['voucherNumber'] : '',
            CalendarDate::of(is_string($data['voucherDate'] ?? null) ? $data['voucherDate'] : ''),
            is_string($data['due'] ?? null) ? CalendarDate::of($data['due']) : null,
            (bool) ($data['recurring'] ?? false),
            is_int($data['economicYear'] ?? null) ? $data['economicYear'] : null,
            is_string($data['supplierTaxationMethod'] ?? null) ? $data['supplierTaxationMethod'] : null,
            is_string($data['serviceDate'] ?? null) ? CalendarDate::of($data['serviceDate']) : null,
            is_string($servicePeriod['from'] ?? null) ? CalendarDate::of($servicePeriod['from']) : null,
            is_string($servicePeriod['to'] ?? null) ? CalendarDate::of($servicePeriod['to']) : null,
            is_string($data['kind'] ?? null) ? $data['kind'] : null,
            is_string($data['partnerId'] ?? null) ? Uuid::fromString($data['partnerId']) : null,
            is_string($data['issuer'] ?? null) ? $data['issuer'] : null,
        );
    }


}
