<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Shared;

use Rechnungswesen\Core\Shared\Exception\InvalidValue;

/**
 * Kanonisches JSON nach RFC 8785 (JCS) — Grundlage aller Hashes und
 * Determinismus-Vergleiche (datenformat.md Grundsatz 1, determinismus.md §5).
 *
 * Abweichung vom vollen RFC, bewusst: Floats werden abgelehnt statt
 * ECMAScript-serialisiert — das Datenformat verbietet JSON-Number für
 * Beträge ohnehin (Float-Verbot), und Ganzzahlen (sequenceNumber, year)
 * sind exakt darstellbar. Schlüsselsortierung erfolgt RFC-konform nach
 * UTF-16-Code-Units, ohne mbstring-Abhängigkeit.
 *
 * Eingaben: Skalare, Listen, assoziative Arrays (= Objekte), stdClass,
 * JsonSerializable. Ein leeres PHP-Array gilt als leere Liste `[]`;
 * für ein leeres Objekt `{}` stdClass verwenden.
 */
final class CanonicalJson
{
    private const int MAX_SAFE_INTEGER = 9007199254740991; // 2^53 - 1

    private function __construct()
    {
    }

    public static function encode(mixed $value): string
    {
        return self::encodeValue($value);
    }

    private static function encodeValue(mixed $value): string
    {
        if ($value instanceof \JsonSerializable) {
            return self::encodeValue($value->jsonSerialize());
        }

        if ($value instanceof \stdClass) {
            return self::encodeObject((array) $value);
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            if (abs($value) > self::MAX_SAFE_INTEGER) {
                throw new InvalidValue(sprintf(
                    'Ganzzahl außerhalb des sicheren Bereichs (|x| > 2^53-1): %d',
                    $value,
                ));
            }

            return (string) $value;
        }

        if (is_float($value)) {
            throw new InvalidValue(
                'Floats sind im Datenformat verboten (Beträge als String-Dezimal, datenformat.md)',
            );
        }

        if (is_string($value)) {
            return self::encodeString($value);
        }

        if (is_array($value)) {
            if ($value === [] || array_is_list($value)) {
                return '[' . implode(',', array_map(self::encodeValue(...), $value)) . ']';
            }

            return self::encodeObject($value);
        }

        throw new InvalidValue(sprintf('Nicht serialisierbarer Typ: %s', get_debug_type($value)));
    }

    /**
     * @param array<array-key, mixed> $pairs
     */
    private static function encodeObject(array $pairs): string
    {
        if ($pairs === []) {
            return '{}';
        }

        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($pairs));
        $values = array_values($pairs);

        $order = range(0, count($keys) - 1);
        usort($order, static fn (int $a, int $b): int => strcmp(
            self::utf16SortKey($keys[$a]),
            self::utf16SortKey($keys[$b]),
        ));

        $members = [];
        foreach ($order as $index) {
            $members[] = self::encodeString($keys[$index]) . ':' . self::encodeValue($values[$index]);
        }

        return '{' . implode(',', $members) . '}';
    }

    /**
     * JCS-Stringserialisierung (RFC 8785 §3.2.2.2): kurze Escapes für
     * die üblichen Steuerzeichen, \u00xx (lowercase) für den Rest unter
     * U+0020, alles andere als rohes UTF-8.
     */
    private static function encodeString(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidValue('String ist kein gültiges UTF-8');
        }

        $out = '"';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $byte = $value[$i];
            $code = ord($byte);

            $out .= match (true) {
                $byte === '"' => '\\"',
                $byte === '\\' => '\\\\',
                $code === 0x08 => '\b',
                $code === 0x09 => '\t',
                $code === 0x0A => '\n',
                $code === 0x0C => '\f',
                $code === 0x0D => '\r',
                $code < 0x20 => sprintf('\u%04x', $code),
                default => $byte,
            };
        }

        return $out . '"';
    }

    /**
     * UTF-16BE-Bytefolge eines UTF-8-Strings: byteweiser Vergleich darauf
     * entspricht exakt der von RFC 8785 geforderten Sortierung nach
     * UTF-16-Code-Units (Surrogatpaare sortieren vor U+E000..U+FFFF).
     */
    private static function utf16SortKey(string $utf8): string
    {
        if (preg_match('//u', $utf8) !== 1) {
            throw new InvalidValue('Schlüssel ist kein gültiges UTF-8');
        }

        $units = '';
        $length = strlen($utf8);
        $i = 0;

        while ($i < $length) {
            $byte = ord($utf8[$i]);

            if ($byte < 0x80) {
                $codepoint = $byte;
                $i += 1;
            } elseif (($byte & 0xE0) === 0xC0) {
                $codepoint = (($byte & 0x1F) << 6)
                    | (ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0) {
                $codepoint = (($byte & 0x0F) << 12)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 6)
                    | (ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $codepoint = (($byte & 0x07) << 18)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((ord($utf8[$i + 2]) & 0x3F) << 6)
                    | (ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            }

            if ($codepoint < 0x10000) {
                $units .= pack('n', $codepoint);
            } else {
                $codepoint -= 0x10000;
                $units .= pack('n', 0xD800 | ($codepoint >> 10));
                $units .= pack('n', 0xDC00 | ($codepoint & 0x3FF));
            }
        }

        return $units;
    }
}
