<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\DomainError;
use Summae\Core\Ledger\Account;
use Summae\Core\Ledger\AccountStatus;
use Summae\Core\Ledger\AccountType;
use Summae\Core\Ledger\FiscalYear;
use Summae\Core\Shared\AccountNumber;
use Summae\Core\Shared\CalendarDate;
use Summae\Core\Shared\Clock;
use Summae\Core\Shared\Currency;
use Summae\Core\Shared\IdGenerator;
use Summae\Core\Tax\TaxCodeRegistry;
use Summae\Core\Tax\TaxProfile;
use Summae\Core\Tenant;

/**
 * `createTenant` (SF-01): Mandant per Profil anlegen — sofort buchbar.
 * Profile sind versionierte Regelmodul-Daten; der Mandant pinnt die
 * Version, kopiert nicht (datenformat.md).
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
            ?? throw new DomainError('E_PROFILE_UNKNOWN', sprintf('Profil "%s" ist nicht vorhanden', $profileId));

        $coaId = is_string($profile['chartOfAccounts'] ?? null) ? $profile['chartOfAccounts'] : '';
        $coa = $this->findById('chartsOfAccounts', $coaId)
            ?? throw new DomainError('E_PROFILE_UNKNOWN', sprintf('Kontenrahmen "%s" des Profils fehlt', $coaId));

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

        // packPolicy ist Pack-Parameter (Geld-Skala + Steuer-Granularität), nicht global.
        $packPolicy = is_array($this->ruleModules['packPolicy'] ?? null) ? $this->ruleModules['packPolicy'] : null;
        $currencyScale = is_int($packPolicy['currencyScale'] ?? null) ? $packPolicy['currencyScale'] : null;
        $granularity = is_string($packPolicy['taxRoundingGranularity'] ?? null)
            ? $packPolicy['taxRoundingGranularity']
            : 'perVoucher';

        $tenant = Tenant::inMemory(
            is_string($input['name'] ?? null) ? $input['name'] : 'Tenant',
            Currency::of(is_string($input['baseCurrency'] ?? null) ? $input['baseCurrency'] : 'EUR', $currencyScale),
            $this->clock,
            $this->ids,
            null,
            TaxCodeRegistry::fromData($taxCodeData),
            $taxProfile,
            null,
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
