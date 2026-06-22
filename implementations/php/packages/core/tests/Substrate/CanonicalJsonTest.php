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
     * RFC 8785: sorting by UTF-16 code units, not codepoints.
     * U+1F600 (surrogate pair, D83D DE00) sorts BEFORE U+FB33 (FB33),
     * even though the codepoint is larger.
     */
    public function testSortsByUtf16CodeUnitsNotCodepoints(): void
    {
        $encoded = CanonicalJson::encode(["\u{FB33}" => 1, "\u{1F600}" => 2]);

        self::assertSame('{"' . "\u{1F600}" . '":2,"' . "\u{FB33}" . '":1}', $encoded);
    }

    public function testNumericStringKeysStayStrings(): void
    {
        // PHP turns "81" into an int key internally — the output must carry it as a string.
        self::assertSame('{"66":2,"81":1}', CanonicalJson::encode(['81' => 1, '66' => 2]));
    }

    public function testStringEscaping(): void
    {
        // U+0001 has no short escape -> hex escape lowercase (RFC 8785)
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
