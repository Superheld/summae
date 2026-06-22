<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Substrate;

use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\CanonicalJson;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\Money;

final class CanonicalJsonTest extends TestCase
{
    public function testSortsObjectKeys(): void
    {
        self::assertSame(
            '{"a":2,"b":1}',
            CanonicalJson::encode(['b' => 1, 'a' => 2]),
        );
    }

    public function testSortsNestedAndPreservesArrayOrder(): void
    {
        self::assertSame(
            '{"list":[3,1,2],"obj":{"x":null,"y":true}}',
            CanonicalJson::encode(['obj' => ['y' => true, 'x' => null], 'list' => [3, 1, 2]]),
        );
    }

    /**
     * RFC 8785: Sortierung nach UTF-16-Code-Units, nicht Codepoints.
     * U+1F600 (Surrogatpaar, D83D DE00) sortiert VOR U+FB33 (FB33),
     * obwohl der Codepoint größer ist.
     */
    public function testSortsByUtf16CodeUnitsNotCodepoints(): void
    {
        $encoded = CanonicalJson::encode(["\u{FB33}" => 1, "\u{1F600}" => 2]);

        self::assertSame('{"' . "\u{1F600}" . '":2,"' . "\u{FB33}" . '":1}', $encoded);
    }

    public function testNumericStringKeysStayStrings(): void
    {
        // PHP macht "81" intern zum int-Key — die Ausgabe muss ihn als String tragen.
        self::assertSame('{"66":2,"81":1}', CanonicalJson::encode(['81' => 1, '66' => 2]));
    }

    public function testStringEscaping(): void
    {
        // U+0001 hat kein Kurz-Escape -> Hex-Escape lowercase (RFC 8785)
        $expected = '"Zeile\n\tTab \u0001 \"quote\" \\\\ ü€"';
        self::assertSame($expected, CanonicalJson::encode("Zeile\n\tTab \x01 \"quote\" \\ ü€"));
    }

    public function testIntegersSerializePlain(): void
    {
        self::assertSame('[0,-42,9007199254740991]', CanonicalJson::encode([0, -42, 9007199254740991]));
    }

    public function testRejectsFloats(): void
    {
        $this->expectException(InvalidValue::class);
        CanonicalJson::encode(['amount' => 12.34]);
    }

    public function testRejectsUnsafeIntegers(): void
    {
        $this->expectException(InvalidValue::class);
        CanonicalJson::encode(9007199254740992);
    }

    public function testEmptyArrayIsListEmptyObjectViaStdClass(): void
    {
        self::assertSame('[]', CanonicalJson::encode([]));
        self::assertSame('{}', CanonicalJson::encode(new \stdClass()));
    }

    public function testJsonSerializableIsUnwrapped(): void
    {
        self::assertSame(
            '{"amount":"1234.56","currency":"EUR"}',
            CanonicalJson::encode(Money::of('1234.56', 'EUR')),
        );
    }

    public function testDeterministicForEquivalentInputs(): void
    {
        $a = ['z' => [1, 2], 'a' => ['k' => 'v', 'b' => 'w']];
        $b = ['a' => ['b' => 'w', 'k' => 'v'], 'z' => [1, 2]];

        self::assertSame(CanonicalJson::encode($a), CanonicalJson::encode($b));
    }
}
