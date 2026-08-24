<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Substrate;

use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\Rational;

/**
 * Rational exists so that the simultaneous-equation solver never rounds. These tests pin the two
 * properties that claim depends on — exactness and floor semantics — plus the refusals, because a
 * silent fallback here would be invisible in the numbers it produced. Node twin: `rational.test.ts`.
 */
final class RationalTest extends TestCase
{
    public function testKeepsThirdsExact(): void
    {
        $third = Rational::of(1, 3);
        $whole = $third->add($third)->add($third);

        self::assertSame('1', (string) $whole->numerator);
        self::assertSame('1', (string) $whole->denominator);
    }

    public function testNormalisesSignAndLowestTerms(): void
    {
        $value = Rational::of(4, -8);

        self::assertSame('-1', (string) $value->numerator);
        self::assertSame('2', (string) $value->denominator);
    }

    public function testReadsDecimalStringsExactly(): void
    {
        $value = Rational::fromDecimalString('0.25');

        self::assertSame('1', (string) $value->numerator);
        self::assertSame('4', (string) $value->denominator);
        self::assertSame(0, Rational::fromDecimalString('-3')->compareTo(Rational::of(-3)));
    }

    /**
     * Floor, not truncation. -0.5 becomes -1 so that the remainder stays in [0, 1) on both sides of
     * zero — the property that lets largest-remainder rounding work unchanged for a cost centre
     * carrying a credit.
     */
    public function testFloorsTowardsNegativeInfinity(): void
    {
        self::assertSame('-1', (string) Rational::of(-1, 2)->floorToBigInteger());
        self::assertSame('0', (string) Rational::of(1, 2)->floorToBigInteger());
        self::assertSame('-3', (string) Rational::of(-3)->floorToBigInteger());

        $fraction = Rational::of(-1, 2)->fractionalPart();
        self::assertSame('1', (string) $fraction->numerator);
        self::assertSame('2', (string) $fraction->denominator);
    }

    public function testComparesAndSubtracts(): void
    {
        self::assertSame(1, Rational::of(2, 3)->compareTo(Rational::of(1, 2)));
        self::assertSame(-1, Rational::of(1, 2)->compareTo(Rational::of(2, 3)));
        self::assertSame(0, Rational::of(2, 4)->compareTo(Rational::of(1, 2)));
        self::assertTrue(Rational::of(1, 2)->subtract(Rational::of(1, 2))->isZero());
        self::assertTrue(Rational::of(-1, 2)->isNegative());
        self::assertSame(0, Rational::of(1, 2)->divide(Rational::of(1, 4))->compareTo(Rational::of(2)));
    }

    public function testRefusesZeroDenominator(): void
    {
        $this->expectException(InvalidValue::class);
        Rational::of(1, 0);
    }

    public function testRefusesDivisionByZero(): void
    {
        $this->expectException(InvalidValue::class);
        Rational::of(1)->divide(Rational::zero());
    }

    public function testRefusesNonNumericString(): void
    {
        $this->expectException(InvalidValue::class);
        Rational::fromDecimalString('1e3');
    }
}
