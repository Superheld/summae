<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Mapping;

/**
 * Geladene Mappings eines Mandanten. Import-Validierung
 * (Überlappung/Lücken) kommt mit `importMapping` (JOB-008).
 */
final class MappingRegistry
{
    /** @var array<string, Mapping> */
    private array $byId = [];

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param list<mixed> $raw Regelmodul-Daten (ruleModules.mappings)
     */
    public static function fromRuleModules(array $raw): self
    {
        $registry = new self();

        foreach ($raw as $mappingData) {
            if (is_array($mappingData)) {
                $registry->add(Mapping::fromData($mappingData));
            }
        }

        return $registry;
    }

    public function add(Mapping $mapping): void
    {
        $this->byId[$mapping->id] = $mapping;
    }

    public function byId(string $id): ?Mapping
    {
        return $this->byId[$id] ?? null;
    }
}
