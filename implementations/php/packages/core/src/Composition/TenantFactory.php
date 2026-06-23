<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\AccountStatus;
use Summae\Core\Substrate\AccountType;
use Summae\Core\Substrate\FiscalYear;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;
use Summae\Core\Tenant;

/**
 * `createTenant` (SF-01): create a tenant from a profile — immediately postable.
 * Profiles are versioned rule-module data; the tenant pins the
 * version, does not copy (datenformat.md).
 */
final readonly class TenantFactory
{
    /**
     * @param array<string, mixed> $ruleModules profiles, chartsOfAccounts, taxCodes
     */
    public function __construct(
        private array $ruleModules,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
    }

    /**
     * @param array<string, mixed> $input name, baseCurrency, profile, firstFiscalYear
     *
     * @return array{tenant: Tenant, result: array<string, mixed>}
     */
    public function create(array $input): array
    {
        $profileId = is_string($input['profile'] ?? null) ? $input['profile'] : '';
        $profile = $this->findById('profiles', $profileId)
            ?? throw new DomainError('E_PROFILE_UNKNOWN', sprintf('Profile "%s" does not exist', $profileId));

        $coaId = is_string($profile['chartOfAccounts'] ?? null) ? $profile['chartOfAccounts'] : '';
        $coa = $this->findById('chartsOfAccounts', $coaId)
            ?? throw new DomainError('E_PROFILE_UNKNOWN', sprintf('Chart of accounts "%s" of the profile is missing', $coaId));

        /** @var list<string> $wantedCodes */
        $wantedCodes = is_array($profile['taxCodes'] ?? null) ? array_values($profile['taxCodes']) : [];
        $allTaxCodes = is_array($this->ruleModules['taxCodes'] ?? null) ? $this->ruleModules['taxCodes'] : [];
        /** @var list<array<mixed>> $taxCodeData */
        $taxCodeData = array_values(array_filter(
            $allTaxCodes,
            static fn (mixed $code): bool => is_array($code) && in_array($code['code'] ?? null, $wantedCodes, true),
        ));

        $defaults = is_array($profile['defaults'] ?? null) ? $profile['defaults'] : [];
        $taxProfile = TaxProfile::fromData($defaults);

        // packPolicy is a pack parameter (money scale + tax granularity), not global.
        $packPolicy = is_array($this->ruleModules['packPolicy'] ?? null) ? $this->ruleModules['packPolicy'] : null;
        $currencyScale = is_int($packPolicy['currencyScale'] ?? null) ? $packPolicy['currencyScale'] : null;
        $granularity = is_string($packPolicy['taxRoundingGranularity'] ?? null)
            ? $packPolicy['taxRoundingGranularity']
            : 'perVoucher';

        // Mappings (balance sheet/P&L/cash-basis) from the resolved pack into the tenant's registry —
        // otherwise balanceSheet/incomeStatement do not find the mappings (pack-path parity with the inline path).
        $mappings = MappingRegistry::fromRuleModules(
            is_array($this->ruleModules['mappings'] ?? null) ? array_values($this->ruleModules['mappings']) : [],
        );

        $tenant = Tenant::inMemory(
            is_string($input['name'] ?? null) ? $input['name'] : 'Tenant',
            Currency::of(is_string($input['baseCurrency'] ?? null) ? $input['baseCurrency'] : 'EUR', $currencyScale),
            $this->clock,
            $this->ids,
            null,
            TaxCodeRegistry::fromData($taxCodeData),
            $taxProfile,
            $mappings,
            $granularity,
        );

        $accountCount = 0;
        foreach (is_array($coa['accounts'] ?? null) ? $coa['accounts'] : [] as $accountData) {
            if (!is_array($accountData)) {
                continue;
            }

            $tenant->accounts->add(new Account(
                $tenant->ids->next(),
                AccountNumber::of(is_string($accountData['number'] ?? null) ? $accountData['number'] : ''),
                is_string($accountData['name'] ?? null) ? $accountData['name'] : '',
                AccountType::from(is_string($accountData['type'] ?? null) ? $accountData['type'] : ''),
                is_string($accountData['subtype'] ?? null) ? $accountData['subtype'] : null,
                AccountStatus::Active,
            ));
            $accountCount++;
        }

        $year = is_int($input['firstFiscalYear'] ?? null) ? $input['firstFiscalYear'] : 0;
        if ($year > 0) {
            $tenant->fiscalYears->add(FiscalYear::create(
                $tenant->ids->next(),
                $year,
                CalendarDate::of(sprintf('%04d-01-01', $year)),
                CalendarDate::of(sprintf('%04d-12-31', $year)),
            ));
        }

        // Asset/depreciation rules from the pack (assetAccounts, depreciation) — parity with the inline path.
        $tenant->assetService->setRuleModule($this->ruleModules);

        return [
            'tenant' => $tenant,
            'result' => [
                'id' => $tenant->id->value,
                'name' => $tenant->name,
                'profile' => [
                    'id' => $profileId,
                    'version' => is_string($profile['version'] ?? null) ? $profile['version'] : '',
                ],
                'accountCount' => $accountCount,
                'taxationMethod' => $taxProfile->taxationMethod(),
            ],
        ];
    }

    /**
     * @return array<mixed>|null
     */
    private function findById(string $module, string $id): ?array
    {
        foreach (is_array($this->ruleModules[$module] ?? null) ? $this->ruleModules[$module] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $id) {
                return $candidate;
            }
        }

        return null;
    }
}
