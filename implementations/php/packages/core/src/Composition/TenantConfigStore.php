<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\Port\TenantRecordRepository;

/**
 * Keeps a tenant's configuration where the books already live (SPEC-015).
 *
 * Five operations change configuration — `setTaxProfile`, `defineDimensionType`,
 * `defineDimensionValue`, `setAllocationScheme` and `importMapping` — and until this existed each
 * of them changed a live object and nothing else. They audited the change durably all the same,
 * which is the part that made it a defect rather than a limitation: the trail stated something the
 * books stopped carrying at the next restart.
 *
 * The store is the one place that writes. Each `remember…` is called by the service that just
 * succeeded, never before, so a rejected operation stores nothing — the same discipline the CLI's
 * `rememberMapping` had to invent for one of the five.
 */
final class TenantConfigStore
{
    public function __construct(
        private readonly TenantRecordRepository $repository,
        private readonly TenantRecord $record,
    ) {
    }

    /**
     * Opens a tenant's stored configuration, seeding it on first open.
     *
     * **The stored record wins.** What the caller passes at construction is a *seed*: it is written
     * on the first open of a tenant that has no record yet, and ignored on every open after that.
     * The alternative — the arguments win when given — sounds harmless and is the state this whole
     * finding came out of: an embedding's configuration file and the library's tables would both
     * claim to hold the truth, and the two would drift the first time an operation changed one.
     *
     * Living with the rule is what makes `defineDimensionType` work: an embedding that used to pass
     * its cost centres in AND declare them (and got E_DIMENSION_INVALID for its trouble) can now do
     * either one.
     *
     * The rule lives in the core, not in the adapters, so both languages cannot answer it
     * differently.
     */
    public static function open(TenantRecordRepository $repository, TenantRecord $seed): self
    {
        $stored = $repository->load();
        if ($stored !== null) {
            return new self($repository, $stored);
        }

        $repository->save($seed);

        return new self($repository, $seed);
    }

    /** @param array<string, mixed> $profile */
    public function rememberTaxProfile(array $profile): void
    {
        $config = $this->record->config;
        $config['taxProfile'] = $profile;
        $this->record->config = $config;
        $this->flush();
    }

    /**
     * @param list<array{code: string}> $types
     * @param list<array{typeCode: string, code: string}> $values
     */
    public function rememberDimensions(array $types, array $values): void
    {
        $config = $this->record->config;
        $config['dimensionTypes'] = $types;
        $config['dimensionValues'] = $values;
        $this->record->config = $config;
        $this->flush();
    }

    /** @param array<string, mixed> $scheme */
    public function rememberAllocationScheme(array $scheme): void
    {
        $config = $this->record->config;
        $config['allocationScheme'] = $scheme;
        $this->record->config = $config;
        $this->flush();
    }

    /**
     * Replace by id rather than append: importing the same id twice must update it, not leave two
     * mappings behind that the next load would read as overlapping. (The rule comes from the CLI,
     * which learned it the hard way; it belongs here now that the library does the storing.)
     *
     * @param array<string, mixed> $mapping
     */
    public function rememberMapping(array $mapping): void
    {
        $config = $this->record->config;
        $id = is_string($mapping['id'] ?? null) ? $mapping['id'] : null;

        $mappings = [];
        $replaced = false;
        foreach ($config['mappings'] as $existing) {
            $existingId = is_string($existing['id'] ?? null) ? $existing['id'] : null;
            if ($existingId === $id) {
                $mappings[] = $mapping;
                $replaced = true;
                continue;
            }
            $mappings[] = $existing;
        }
        if (!$replaced) {
            $mappings[] = $mapping;
        }

        $config['mappings'] = $mappings;
        $this->record->config = $config;
        $this->flush();
    }

    /** What is stored right now — the seed written at first open, or what the operations made of it. */
    public function record(): TenantRecord
    {
        return $this->record;
    }

    private function flush(): void
    {
        $this->repository->save($this->record);
    }
}
