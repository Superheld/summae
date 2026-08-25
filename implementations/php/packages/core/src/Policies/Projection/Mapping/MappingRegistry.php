<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection\Mapping;

/**
 * Loaded mappings of a tenant. Import validation
 * (overlap/gaps) comes with `importMapping` (JOB-008).
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
     * @param list<mixed> $raw rule module data (ruleModules.mappings)
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

    /**
     * Which mappings are in force, by name — what `tenantConfiguration` publishes.
     *
     * Identity only, never the positions. The definitions are the pack's, and the handbook rule
     * that keeps them there is deliberate: the embedding pins and ships the pack, summae takes it
     * on every open and stores no copy, because two answers to "which rules is this tenant on" is
     * one answer too many. Publishing the leaves here would create exactly that second answer.
     *
     * What a caller cannot know without this is the part that is genuinely summae's: which
     * `mapping` names `balanceSheet`, `incomeStatement` and `cashBasisReport` will accept —
     * the pack's, plus whatever `importMapping` layered on top or replaced.
     *
     * Sorted by id, so the answer does not depend on the order the registry was filled in.
     *
     * @return list<array{id: string, kind: string, version: string}>
     */
    public function summaries(): array
    {
        $ids = array_keys($this->byId);
        sort($ids, SORT_STRING);

        return array_map(
            fn (string $id): array => [
                'id' => $id,
                'kind' => $this->byId[$id]->kind,
                'version' => $this->byId[$id]->version,
            ],
            $ids,
        );
    }
}
