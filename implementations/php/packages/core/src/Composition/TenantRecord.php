<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

/**
 * What a tenant *is*, apart from its books (SPEC-015): its identity and the configuration it was
 * set up with.
 *
 * Deliberately raw data rather than the built objects — what goes in is exactly what
 * `TaxProfile::fromData`, `DimensionRegistry::fromData`, `setAllocationScheme` and `importMapping`
 * accept, so the round trip is symmetric by construction and no second serializer can drift from
 * the first one.
 *
 * Mutable on purpose: the store hands the same record to every `remember…` and writes it whole.
 *
 * @phpstan-type TenantConfigShape array{
 *   taxProfile: array<string, mixed>|null,
 *   dimensionTypes: list<array{code: string}>,
 *   dimensionValues: list<array{typeCode: string, code: string}>,
 *   allocationScheme: array<string, mixed>|null,
 *   mappings: list<array<string, mixed>>,
 * }
 */
final class TenantRecord
{
    /**
     * @param array{id: string, version: string}|null $packIdentity
     * @param TenantConfigShape $config
     */
    public function __construct(
        public readonly string $id,
        public string $name,
        public string $baseCurrency,
        public ?array $packIdentity,
        public array $config,
    ) {
    }

    /**
     * An empty configuration block — the shape every record carries, with nothing configured yet.
     *
     * @return TenantConfigShape
     */
    public static function emptyConfig(): array
    {
        return [
            'taxProfile' => null,
            'dimensionTypes' => [],
            'dimensionValues' => [],
            'allocationScheme' => null,
            'mappings' => [],
        ];
    }
}
