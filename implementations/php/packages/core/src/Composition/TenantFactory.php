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
use Summae\Core\Policies\Constraint\AccountCombinationRegistry;
use Summae\Core\Policies\Constraint\DimensionRegistry;
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

        // packPolicy is a pack parameter (money scale, tax granularity, filing windows), not global.
        $packPolicy = is_array($this->ruleModules['packPolicy'] ?? null) ? $this->ruleModules['packPolicy'] : null;

        // Which filing windows exist is the pack's answer, not the substrate's (SPEC-016). A pack
        // that says nothing gets the substrate's default; one that says something replaces it.
        /** @var list<string>|null $vatPeriods */
        $vatPeriods = is_array($packPolicy['vatPeriods'] ?? null)
            ? array_values(array_filter($packPolicy['vatPeriods'], is_string(...)))
            : null;

        $defaults = is_array($profile['defaults'] ?? null) ? $profile['defaults'] : [];
        $taxProfile = TaxProfile::fromData($defaults, $vatPeriods);

        $currencyScale = is_int($packPolicy['currencyScale'] ?? null) ? $packPolicy['currencyScale'] : null;
        $granularity = is_string($packPolicy['taxRoundingGranularity'] ?? null)
            ? $packPolicy['taxRoundingGranularity']
            : 'perVoucher';

        // Mappings (balance sheet/P&L/cash-basis) from the resolved pack into the tenant's registry —
        // otherwise balanceSheet/incomeStatement do not find the mappings (pack-path parity with the inline path).
        $mappings = MappingRegistry::fromRuleModules(
            is_array($this->ruleModules['mappings'] ?? null) ? array_values($this->ruleModules['mappings']) : [],
        );

        // Constraint plugs from the pack. Types and values stay the tenant's own master data
        // (defineDimensionType/Value) — a jurisdiction has no opinion about what a company calls its
        // cost centres — but WHICH ACCOUNTS MAY NOT BE POSTED WITHOUT ONE is a rule a pack can hold,
        // and until now no pack could: the registry was built with nothing at all.
        /** @var list<array{accountRange: array{from: string, to: string}, requiredDimension: string}> $dimensionRules */
        $dimensionRules = is_array($this->ruleModules['dimensionRules'] ?? null)
            ? array_values($this->ruleModules['dimensionRules'])
            : [];

        /** @var list<array<string, mixed>> $accountCombinationRules */
        $accountCombinationRules = is_array($this->ruleModules['accountCombinationRules'] ?? null)
            ? array_values($this->ruleModules['accountCombinationRules'])
            : [];

        /** @var list<array<string, mixed>> $accountUsageRules */
        $accountUsageRules = is_array($this->ruleModules['accountUsageRules'] ?? null)
            ? array_values($this->ruleModules['accountUsageRules'])
            : [];

        $tenant = Tenant::inMemory(
            is_string($input['name'] ?? null) ? $input['name'] : 'Tenant',
            Currency::of(is_string($input['baseCurrency'] ?? null) ? $input['baseCurrency'] : 'EUR', $currencyScale),
            $this->clock,
            $this->ids,
            DimensionRegistry::fromData([], [], $dimensionRules),
            TaxCodeRegistry::fromData($taxCodeData),
            $taxProfile,
            $mappings,
            $granularity,
            $this->packIdentity(),
            null,
            AccountCombinationRegistry::fromData($accountCombinationRules, $accountUsageRules),
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
        // And the same for costing: the pack decides which components may enter production cost.
        $tenant->costing->setRuleModule($this->ruleModules);
        // And the appropriation plug: which account a resolution books against, and which targets exist.
        $tenant->resultAppropriation->setRuleModule($this->ruleModules);
        // And the legal-form catalogue: which forms this jurisdiction knows, and what each obliges.
        $tenant->legalForms->setRuleModule($this->ruleModules);
        // And the stock categories: which accounts hold stock, and where each one's change is booked.
        $tenant->inventory?->setRuleModule($this->ruleModules);
        $tenant->provisionService?->setRuleModule($this->ruleModules);
        $tenant->deferralService?->setRuleModule($this->ruleModules);

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
     * The pack the resolved bundle came from, when it came from one. An inline bundle has no
     * manifest, so there is nothing to name and the description says so rather than guessing.
     *
     * @return array{id: string, version: string}|null
     */
    private function packIdentity(): ?array
    {
        $pack = $this->ruleModules['pack'] ?? null;
        if (!is_array($pack)) {
            return null;
        }
        $id = $pack['id'] ?? null;
        $version = $pack['version'] ?? null;

        return is_string($id) && is_string($version) ? ['id' => $id, 'version' => $version] : null;
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
