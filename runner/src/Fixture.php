<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner;

final readonly class Fixture
{
    /**
     * @param array<string, mixed> $setup
     * @param list<array<string, mixed>> $steps
     * @param list<array<string, mixed>> $projections
     */
    private function __construct(
        public string $name,
        public string $file,
        public array $setup,
        public array $steps,
        public array $projections,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Fixture nicht lesbar: %s', $path));
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $name = $data['fixture'] ?? basename($path, '.json');
        $setup = $data['setup'] ?? [];
        $steps = $data['steps'] ?? [];
        $projections = $data['projections'] ?? [];

        if (!is_string($name) || !is_array($setup) || !is_array($steps) || !is_array($projections)) {
            throw new \RuntimeException(sprintf('Fixture hat unerwartete Struktur: %s', $path));
        }

        /**
         * @var array<string, mixed> $setup
         * @var list<array<string, mixed>> $steps
         * @var list<array<string, mixed>> $projections
         */
        return new self($name, $path, $setup, $steps, $projections);
    }
}
