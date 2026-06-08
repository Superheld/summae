<?php

declare(strict_types=1);

namespace Summae\Runner;

/**
 * Teilmengen-Vergleich nach Runner-Kontrakt (testsuite/README.md):
 * nur in expect angegebene Felder werden geprüft; Listen exakt in Länge
 * und Reihenfolge (Projektionen sind normiert sortiert); Beträge exakt
 * als Strings; "comment"-Schlüssel sind Doku und werden ignoriert;
 * Platzhalter binden beim ersten Auftreten und vergleichen danach.
 */
final class Comparator
{
    private function __construct()
    {
    }

    /**
     * @return list<string> Abweichungen, leer = Übereinstimmung
     */
    public static function diff(mixed $expected, mixed $actual, PlaceholderBag $bag, string $path = '$'): array
    {
        if (PlaceholderBag::isPlaceholder($expected)) {
            /** @var string $expected */
            if (!is_string($actual)) {
                return [sprintf('%s: Platzhalter %s erwartet einen String, ist %s', $path, $expected, self::show($actual))];
            }

            if (!$bag->has($expected)) {
                $bag->bind($expected, $actual);

                return [];
            }

            return $bag->get($expected) === $actual
                ? []
                : [sprintf('%s: Platzhalter %s = "%s", ist "%s"', $path, $expected, $bag->get($expected), $actual)];
        }

        if (is_array($expected)) {
            if (!is_array($actual)) {
                return [sprintf('%s: erwartet %s, ist %s', $path, self::show($expected), self::show($actual))];
            }

            if (array_is_list($expected)) {
                if (!array_is_list($actual)) {
                    return [sprintf('%s: Liste erwartet, ist Objekt', $path)];
                }

                if (count($expected) !== count($actual)) {
                    return [sprintf('%s: Listenlänge %d erwartet, ist %d', $path, count($expected), count($actual))];
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
                    continue; // Doku in der Fixture, kein Vergleichsinhalt
                }

                if (!array_key_exists($key, $actual)) {
                    $diffs[] = sprintf('%s.%s: Feld fehlt im Ergebnis', $path, $key);
                    continue;
                }

                $diffs = [...$diffs, ...self::diff($value, $actual[$key], $bag, sprintf('%s.%s', $path, $key))];
            }

            return $diffs;
        }

        return $expected === $actual
            ? []
            : [sprintf('%s: erwartet %s, ist %s', $path, self::show($expected), self::show($actual))];
    }

    private static function show(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $json === false ? get_debug_type($value) : $json;

        return strlen($json) > 120 ? substr($json, 0, 117) . '…' : $json;
    }
}
