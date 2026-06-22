<?php

declare(strict_types=1);

namespace Summae\Cli;

use Illuminate\Database\Capsule\Manager as Capsule;
use Summae\Core\Ledger\DimensionRegistry;
use Summae\Core\Mapping\MappingRegistry;
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
 * CLI-Arbeitsbereich: `summae.json` (Mandanten-Meta + Regelmodul-Daten,
 * App-Schicht) + `summae.sqlite` (Adapter-Persistenz).
 * Jeder Aufruf lädt den Mandanten, führt aus, die Datenbank persistiert.
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
            throw new \RuntimeException(sprintf('Arbeitsbereich existiert bereits: %s', $this->configPath()));
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

    public function tenant(): Tenant
    {
        if (!$this->exists()) {
            throw new \RuntimeException(sprintf(
                'Kein Arbeitsbereich in %s — zuerst `summae init` ausführen',
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

        $tenantId = is_string($config['tenantId'] ?? null) ? Uuid::fromString($config['tenantId']) : null;

        $tenant = (new DatabaseTenantFactory($this->connection()))->build(
            is_string($config['name'] ?? null) ? $config['name'] : 'CLI',
            Currency::of(is_string($config['baseCurrency'] ?? null) ? $config['baseCurrency'] : 'EUR'),
            $clock,
            new UuidV7IdGenerator($clock),
            DimensionRegistry::fromData($dimensionTypes, $dimensionValues, $dimensionRules),
            TaxCodeRegistry::fromData($taxCodes),
            TaxProfile::fromData($taxProfile),
            MappingRegistry::fromRuleModules($mappings),
            $tenantId,
        );

        $tenant->assetService->setRuleModule($ruleModules);

        return $tenant;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        $path = $this->directory . '/' . self::DB_FILE;

        if (!is_file($path)) {
            touch($path); // SQLite-Connector erwartet eine existierende Datei
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
