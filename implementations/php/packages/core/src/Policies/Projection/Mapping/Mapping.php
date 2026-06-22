<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection\Mapping;

/**
 * Structure mapping (datenformat.md v0.2): assigns accounts to positions —
 * same structure for balance sheet, income statement, cash-basis lines and VAT-return reporting keys.
 * Selectors: number ranges (codepoint comparison) and individual accounts.
 */
final readonly class Mapping
{
    /**
     * Leaf positions, flat (hierarchy reconstructable via parentKeys).
     *
     * @param list<array{key: string, label: string, side: ?string, ranges: list<array{from: string, to: string}>, numbers: list<string>, includeNonCash: bool, includesNetIncome: bool, parents: list<string>}> $leaves
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $version,
        public array $leaves,
    ) {
    }

    /**
     * @param array<mixed> $data raw data (rule module)
     */
    public static function fromData(array $data): self
    {
        $leaves = [];
        self::collectLeaves(
            is_array($data['positions'] ?? null) ? array_values($data['positions']) : [],
            [],
            $leaves,
        );

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['kind'] ?? null) ? $data['kind'] : '',
            is_string($data['version'] ?? null) ? $data['version'] : '',
            $leaves,
        );
    }

    /**
     * @param list<mixed> $positions
     * @param list<string> $parents
     * @param list<array{key: string, label: string, side: ?string, ranges: list<array{from: string, to: string}>, numbers: list<string>, includeNonCash: bool, includesNetIncome: bool, parents: list<string>}> $leaves
     */
    private static function collectLeaves(array $positions, array $parents, array &$leaves, ?string $side = null): void
    {
        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }

            $key = is_string($position['key'] ?? null) ? $position['key'] : '';
            // side is set at the root node and inherited by the leaves (v0.5/F-007).
            $nodeSide = is_string($position['side'] ?? null) ? $position['side'] : $side;
            $children = is_array($position['children'] ?? null) ? array_values($position['children']) : [];

            if ($children !== []) {
                self::collectLeaves($children, [...$parents, $key], $leaves, $nodeSide);
                continue;
            }

            $ranges = [];
            $numbers = [];
            foreach (is_array($position['accounts'] ?? null) ? $position['accounts'] : [] as $selector) {
                if (!is_array($selector)) {
                    continue;
                }

                if (is_string($selector['from'] ?? null) && is_string($selector['to'] ?? null)) {
                    $ranges[] = ['from' => $selector['from'], 'to' => $selector['to']];
                }

                foreach (is_array($selector['numbers'] ?? null) ? $selector['numbers'] : [] as $number) {
                    if (is_string($number)) {
                        $numbers[] = $number;
                    }
                }
            }

            $leaves[] = [
                'key' => $key,
                'label' => is_string($position['label'] ?? null) ? $position['label'] : $key,
                'side' => $nodeSide,
                'ranges' => $ranges,
                'numbers' => $numbers,
                'includeNonCash' => ($position['includeNonCash'] ?? false) === true,
                'includesNetIncome' => ($position['includesNetIncome'] ?? false) === true,
                'parents' => $parents,
            ];
        }
    }

    /**
     * @return array{key: string, label: string, side: ?string, ranges: list<array{from: string, to: string}>, numbers: list<string>, includeNonCash: bool, includesNetIncome: bool, parents: list<string>}|null
     */
    public function leafFor(string $accountNumber): ?array
    {
        foreach ($this->leaves as $leaf) {
            if (in_array($accountNumber, $leaf['numbers'], true)) {
                return $leaf;
            }

            foreach ($leaf['ranges'] as $range) {
                if (strcmp($accountNumber, $range['from']) >= 0 && strcmp($accountNumber, $range['to']) <= 0) {
                    return $leaf;
                }
            }
        }

        return null;
    }
}
