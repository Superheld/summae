<?php

declare(strict_types=1);

namespace Summae\Runner;

/**
 * Subset comparison per runner contract (testsuite/README.md):
 * only fields given in expect are checked; lists exact in length
 * and order (projections are normalized-sorted); amounts exact
 * as strings; "comment" keys are documentation and are ignored;
 * placeholders bind on first occurrence and compare thereafter.
 */
final class Comparator
{
    private function __construct()
    {
    }

    /**
     * @return list<string> deviations, empty = match
     */
    public static function diff(mixed $expected, mixed $actual, PlaceholderBag $bag, string $path = '$'): array
    {
        if (PlaceholderBag::isPlaceholder($expected)) {
            /** @var string $expected */
            if (!is_string($actual)) {
                return [sprintf('%s: placeholder %s expects a string, is %s', $path, $expected, self::show($actual))];
            }

            if (!$bag->has($expected)) {
                $bag->bind($expected, $actual);

                return [];
            }

            return $bag->get($expected) === $actual
                ? []
                : [sprintf('%s: placeholder %s = "%s", is "%s"', $path, $expected, $bag->get($expected), $actual)];
        }

        if (is_array($expected)) {
            if (!is_array($actual)) {
                return [sprintf('%s: expected %s, is %s', $path, self::show($expected), self::show($actual))];
            }

            if (array_is_list($expected)) {
                if (!array_is_list($actual)) {
                    return [sprintf('%s: list expected, is object', $path)];
                }

                if (count($expected) !== count($actual)) {
                    return [sprintf('%s: list length %d expected, is %d', $path, count($expected), count($actual))];
                }

                $diffs = [];
                foreach ($expected as $index => $item) {
                    $diffs = [...$diffs, ...self::diff($item, $actual[$index], $bag, sprintf('%s[%d]', $path, $index))];
                }

                return $diffs;
            }

            $diffs = [];
            foreach ($expected as $key => $value) {
                if ($key === 'comment') {
                    continue; // documentation in the fixture, not comparison content
                }

                if (!array_key_exists($key, $actual)) {
                    $diffs[] = sprintf('%s.%s: field missing in result', $path, $key);
                    continue;
                }

                $diffs = [...$diffs, ...self::diff($value, $actual[$key], $bag, sprintf('%s.%s', $path, $key))];
            }

            return $diffs;
        }

        return $expected === $actual
            ? []
            : [sprintf('%s: expected %s, is %s', $path, self::show($expected), self::show($actual))];
    }

    private static function show(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $json === false ? get_debug_type($value) : $json;

        return strlen($json) > 120 ? substr($json, 0, 117) . '…' : $json;
    }
}
