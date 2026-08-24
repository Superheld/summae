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

    /**
     * Write an imported mapping into the workspace file.
     *
     * Mappings live in a registry that is rebuilt from `summae.json` on every invocation, so an
     * import that only touched the registry was gone the moment the process ended: `imported: true`
     * followed by a report that behaved as though nothing had been imported. Called only after the
     * import succeeded, so a rejected mapping is never stored.
     *
     * @param array<string, mixed> $mapping
     */
    public function rememberMapping(array $mapping): void
    {
        $raw = file_get_contents($this->configPath());
        /** @var array<string, mixed> $config */
        $config = json_decode(is_string($raw) ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $rules */
        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        /** @var array<string, mixed> $ruleModules */
        $ruleModules = is_array($rules['ruleModules'] ?? null) ? $rules['ruleModules'] : [];
        /** @var list<mixed> $mappings */
        $mappings = is_array($ruleModules['mappings'] ?? null) ? array_values($ruleModules['mappings']) : [];

        // Replace by id rather than append: importing the same id twice must update it, not leave
        // two mappings behind that the next load would read as overlapping.
        $id = is_string($mapping['id'] ?? null) ? $mapping['id'] : null;
        $replaced = false;
        foreach ($mappings as $index => $existing) {
            if (is_array($existing) && ($existing['id'] ?? null) === $id) {
                $mappings[$index] = $mapping;
                $replaced = true;
                break;
            }
        }

        if (!$replaced) {
            $mappings[] = $mapping;
        }

        $ruleModules['mappings'] = $mappings;
        $rules['ruleModules'] = $ruleModules;
        $config['rules'] = $rules;

        file_put_contents($this->configPath(), json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
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
        );

        $tenant->assetService->setRuleModule($ruleModules);
        $tenant->costing->setRuleModule($ruleModules);

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
}
