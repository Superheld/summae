<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Mapping;

/**
 * Gliederungs-Mapping (datenformat.md v0.2): ordnet Konten Positionen zu —
 * gleiche Struktur für Bilanz, GuV, EÜR-Zeilen und VA-Kennzahlen.
 * Selektoren: Nummernbereiche (Codepoint-Vergleich) und Einzelkonten.
 */
final readonly class Mapping
{
    /**
     * Blattpositionen, flach (Hierarchie über parentKeys rekonstruierbar).
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
     * @param array<mixed> $data Rohdaten (Regelmodul)
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
            // side wird am Wurzelknoten gesetzt und an die Blätter vererbt (v0.5/F-007).
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
