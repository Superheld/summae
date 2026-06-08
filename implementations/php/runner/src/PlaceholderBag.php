<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner;

/**
 * Platzhalter-Mechanik der Fixtures (testsuite/README.md):
 * "$V1", "$E1", … stehen für IDs, die die Implementierung selbst erzeugt.
 *
 * - In setup/input wird ein unbekannter Platzhalter an eine frische ID
 *   gebunden, ein bekannter durch seinen Wert ersetzt.
 * - In expect wird ein unbekannter Platzhalter an den Ist-Wert gebunden
 *   (Capture), ein bekannter muss exakt übereinstimmen.
 */
final class PlaceholderBag
{
    /** @var array<string, string> */
    private array $values = [];

    public static function isPlaceholder(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\$[A-Za-z0-9_]+$/', $value) === 1;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): string
    {
        return $this->values[$name]
            ?? throw new \LogicException(sprintf('Platzhalter %s ist nicht gebunden', $name));
    }

    public function bind(string $name, string $value): void
    {
        if ($this->has($name) && $this->values[$name] !== $value) {
            throw new \LogicException(sprintf(
                'Platzhalter %s ist bereits an "%s" gebunden',
                $name,
                $this->values[$name],
            ));
        }

        $this->values[$name] = $value;
    }

    /**
     * Ersetzt rekursiv alle Platzhalter in Eingabedaten. Unbekannte werden
     * über $onUnknown an einen frischen Wert gebunden.
     *
     * @param callable(string): string $onUnknown
     */
    public function resolve(mixed $data, callable $onUnknown): mixed
    {
        if (is_array($data)) {
            return array_map(fn (mixed $item): mixed => $this->resolve($item, $onUnknown), $data);
        }

        if (self::isPlaceholder($data)) {
            /** @var string $data */
            if (!$this->has($data)) {
                $this->bind($data, $onUnknown($data));
            }

            return $this->get($data);
        }

        return $data;
    }
}
