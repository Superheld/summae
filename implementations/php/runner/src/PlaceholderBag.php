<?php

declare(strict_types=1);

namespace Summae\Runner;

/**
 * Placeholder mechanics of the fixtures (testsuite/README.md):
 * "$V1", "$E1", … stand for IDs that the implementation generates itself.
 *
 * - In setup/input an unknown placeholder is bound to a fresh ID,
 *   a known one is replaced by its value.
 * - In expect an unknown placeholder is bound to the actual value
 *   (capture), a known one must match exactly.
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
            ?? throw new \LogicException(sprintf('Placeholder %s is not bound', $name));
    }

    public function bind(string $name, string $value): void
    {
        if ($this->has($name) && $this->values[$name] !== $value) {
            throw new \LogicException(sprintf(
                'Placeholder %s is already bound to "%s"',
                $name,
                $this->values[$name],
            ));
        }

        $this->values[$name] = $value;
    }

    /**
     * Recursively replaces all placeholders in input data. Unknown ones are
     * bound to a fresh value via $onUnknown.
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
