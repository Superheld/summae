<?php

declare(strict_types=1);

namespace Summae\Cli;

use Summae\Core\DomainError;
use Illuminate\Database\Capsule\Manager as Capsule;
use Summae\Core\Policies\Constraint\DimensionRegistry;
use Summae\Core\Policies\Projection\Mapping\MappingRegistry;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\SystemClock;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\UuidV7IdGenerator;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;
use Summae\Core\Tenant;
use Summae\Laravel\DatabaseTenantFactory;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * CLI workspace: `summae.json` (tenant meta + pack data,
 * app layer) + `summae.sqlite` (adapter persistence).
 * Each invocation loads the tenant, runs, the database persists.
 */
final class Workspace
{
    private const string CONFIG_FILE = 'summae.json';

    private const string DB_FILE = 'summae.sqlite';

    /** @var array<string, mixed> */
    private array $config = [];

    private function __construct(
        private readonly string $directory,
    ) {
    }

    public static function in(string $directory): self
    {
        return new self(rtrim($directory, '/'));
    }

    public function exists(): bool
    {
        return is_file($this->configPath());
    }

    /**
     * @param array<string, mixed> $ruleData accounts, taxCodes, taxProfile, dimensionTypes/-Values, ruleModules
     */
    public function initialize(string $name, string $currency, array $ruleData): void
    {
        if ($this->exists()) {
            throw new \RuntimeException(sprintf('Workspace already exists: %s', $this->configPath()));
        }

        $this->config = [
            'name' => $name,
            'baseCurrency' => $currency,
            'tenantId' => Uuid::v7()->value,
            'rules' => $ruleData,
        ];

        file_put_contents(
            $this->configPath(),
            json_encode($this->config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        $connection = $this->connection();
        SchemaInstaller::create($connection->getSchemaBuilder());
    }

    /**
     * Remove what `initialize` wrote. Used when creating master data fails afterwards: a workspace
     * that exists but could not be filled is worse than none, because `init` refuses to run again.
     */
    public function discard(): void
    {
        foreach ([$this->configPath(), $this->directory . '/' . self::DB_FILE] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * A workspace file is a contract, not a suggestion: a missing or unusable field is reported
     * rather than replaced by something plausible.
     *
     * @param array<string, mixed> $config
     */
    private static function requireString(array $config, string $field): string
    {
        $value = $config[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new DomainError(
                'E_WORKSPACE_INVALID',
                sprintf('summae.json: "%s" is missing or not a string', $field),
                ['field' => $field],
            );
        }

        return $value;
    }

    public function tenant(): Tenant
    {
        if (!$this->exists()) {
            throw new \RuntimeException(sprintf(
                'No workspace in %s — run `summae init` first',
                $this->directory,
            ));
        }

        $raw = file_get_contents($this->configPath());
        /** @var array<string, mixed> $config */
        $config = json_decode(is_string($raw) ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);
        $this->config = $config;

        /** @var array<string, mixed> $rules */
        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];

        /** @var list<array{code: string}> $dimensionTypes */
        $dimensionTypes = is_array($rules['dimensionTypes'] ?? null) ? array_values($rules['dimensionTypes']) : [];
        /** @var list<array{typeCode: string, code: string}> $dimensionValues */
        $dimensionValues = is_array($rules['dimensionValues'] ?? null) ? array_values($rules['dimensionValues']) : [];
        /** @var array<string, mixed> $ruleModules */
        $ruleModules = is_array($rules['ruleModules'] ?? null) ? $rules['ruleModules'] : [];
        /** @var list<array{accountRange: array{from: string, to: string}, requiredDimension: string}> $dimensionRules */
        $dimensionRules = is_array($ruleModules['dimensionRules'] ?? null) ? array_values($ruleModules['dimensionRules']) : [];
        /** @var list<array<mixed>> $taxCodes */
        $taxCodes = array_values(array_filter(
            is_array($rules['taxCodes'] ?? null) ? $rules['taxCodes'] : [],
            is_array(...),
        ));
        /** @var array<mixed> $taxProfile */
        $taxProfile = is_array($rules['taxProfile'] ?? null) ? $rules['taxProfile'] : [];
        /** @var list<mixed> $mappings */
        $mappings = is_array($ruleModules['mappings'] ?? null) ? array_values($ruleModules['mappings']) : [];

        $clock = new SystemClock();

        // Every field used to fall back to a default and the tenantId was regenerated when
        // missing, so a damaged-but-parseable config opened the same database under a different
        // identity and reported an empty ledger — indistinguishable from books never written.
        $rawTenantId = self::requireString($config, 'tenantId');
        $name = self::requireString($config, 'name');
        $baseCurrency = self::requireString($config, 'baseCurrency');

        try {
            $tenantId = Uuid::fromString($rawTenantId);
        } catch (\Throwable) {
            throw new DomainError('E_WORKSPACE_INVALID', 'summae.json: "tenantId" is not a UUID', ['field' => 'tenantId']);
        }

        // Seed values (name, currency, profile, dimension master data) are written on the first open
        // and ignored afterwards — the workspace file stops being a second source of truth for them
        // (SPEC-015). The pack side is passed on every open, because that is what a pack is.
        $tenant = (new DatabaseTenantFactory($this->connection()))->build(
            $name,
            Currency::of($baseCurrency),
            $clock,
            new UuidV7IdGenerator($clock),
            DimensionRegistry::fromData($dimensionTypes, $dimensionValues, $dimensionRules),
            TaxCodeRegistry::fromData($taxCodes),
            TaxProfile::fromData($taxProfile),
            MappingRegistry::fromRuleModules($mappings),
            $tenantId,
            // Named, because this is the twelfth parameter of a factory whose tenth is a string:
            // passing it positionally put a declaration where the rounding granularity goes.
            // What this workspace declares about who is behind `actor` (SPEC-020). The CLI itself
            // proves nobody's identity, so the field is absent unless somebody writing summae.json
            // puts it there — and absent stays null, which is not the same as "no".
            actorAuthentication: self::actorAuthentication($config),
        );

        $tenant->assetService->setRuleModule($ruleModules);
        $tenant->costing->setRuleModule($ruleModules);
        $tenant->resultAppropriation->setRuleModule($ruleModules);
        $tenant->legalForms->setRuleModule($ruleModules);
        $tenant->inventory?->setRuleModule($ruleModules);
        $tenant->provisionService?->setRuleModule($ruleModules);
        $tenant->deferralService?->setRuleModule($ruleModules);

        return $tenant;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        $path = $this->directory . '/' . self::DB_FILE;

        if (!is_file($path)) {
            touch($path); // SQLite connector expects an existing file
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => $path,
            'foreign_key_constraints' => false,
        ]);

        return $capsule->getConnection();
    }

    private function configPath(): string
    {
        return $this->directory . '/' . self::CONFIG_FILE;
    }

    /**
     * `actorAuthentication` in summae.json, as `systemDescription` reports it (SPEC-020).
     *
     * ```json
     * "actorAuthentication": { "declared": true, "method": "scrypt password login, signed session cookie" }
     * ```
     *
     * This is the embedding stating a fact about itself, and summae reports it as such — never as
     * its own finding. Absent means nothing was declared, which a reader must not turn into "no":
     * an unanswered question and a denial read differently to an auditor.
     *
     * @param array<string, mixed> $config
     *
     * @return array{declared: bool, method: string|null}|null
     */
    private static function actorAuthentication(array $config): ?array
    {
        $raw = $config['actorAuthentication'] ?? null;
        if (!is_array($raw) || !is_bool($raw['declared'] ?? null)) {
            return null;
        }

        return [
            'declared' => $raw['declared'],
            'method' => is_string($raw['method'] ?? null) ? $raw['method'] : null,
        ];
    }
}
