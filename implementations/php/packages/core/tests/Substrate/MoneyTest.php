<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Substrate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\Exception\CurrencyMismatch;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\Money;

final class MoneyTest extends TestCase
{
    public function testOfNormalizesToCurrencyScale(): void
    {
        self::assertSame('1234.56', Money::of('1234.56', 'EUR')->amountAsString());
        self::assertSame('5.00', Money::of('5', 'EUR')->amountAsString());
        self::assertSame('5.10', Money::of('5.1', 'EUR')->amountAsString());
    }

    public function testOfRejectsExcessScale(): void
    {
        $this->expectException(InvalidValue::class);
        Money::of('1234.567', 'EUR');
    }

    public function testOfRejectsGarbage(): void
    {
        $this->expectException(InvalidValue::class);
        Money::of('12,34', 'EUR');
    }

    /**
     * determinismus.md §2: half-up, von Null weg bei genau .5 — die
     * half-even-Falle (Fixture-Pflichtfall 1).
     */
    #[DataProvider('roundingCases')]
    public function testFromCalculationRoundsHalfUpAwayFromZero(string $input, string $expected): void
    {
        self::assertSame($expected, Money::fromCalculation($input, 'EUR')->amountAsString());
    }

    /** @return iterable<string, array{string, string}> */
    public static function roundingCases(): iterable
    {
        yield 'half-even-Falle' => ['2.225', '2.23'];
        yield 'kaufmännisch positiv' => ['2.345', '2.35'];
        yield 'kaufmännisch negativ (von Null weg)' => ['-2.345', '-2.35'];
        yield 'unter der Mitte' => ['2.2249', '2.22'];
        yield 'über der Mitte' => ['2.2251', '2.23'];
    }

    public function testArithmetic(): void
    {
        $a = Money::of('100.00', 'EUR');
        $b = Money::of('0.05', 'EUR');

        self::assertSame('100.05', $a->add($b)->amountAsString());
        self::assertSame('99.95', $a->subtract($b)->amountAsString());
        self::assertSame('-100.00', $a->negate()->amountAsString());
        self::assertSame('100.00', $a->negate()->abs()->amountAsString());
        self::assertSame(1, $a->compareTo($b));
        self::assertSame(-1, $b->compareTo($a));
        self::assertSame(0, $a->compareTo(Money::of('100.00', 'EUR')));
        self::assertTrue($a->equals(Money::of('100.00', 'EUR')));
        self::assertTrue(Money::zero('EUR')->isZero());
        self::assertTrue($a->isPositive());
        self::assertTrue($a->negate()->isNegative());
    }

    public function testCurrenciesDoNotMix(): void
    {
        $this->expectException(CurrencyMismatch::class);
        Money::of('1.00', 'EUR')->add(Money::of('1.00', 'USD'));
    }

    /**
     * determinismus.md §2 / Fixture-Pflichtfall 3:
     * 100,00 € auf 3 Teile -> 33,34 / 33,33 / 33,33 (Gleichstand -> erster).
     */
    public function testAllocateEvenlyLargestRemainderTieGoesToFirst(): void
    {
        $parts = Money::of('100.00', 'EUR')->allocateEvenly(3);

        self::assertSame(
            ['33.34', '33.33', '33.33'],
            array_map(static fn (Money $m): string => $m->amountAsString(), $parts),
        );
    }

    public function testAllocateByWeights(): void
    {
        $parts = Money::of('100.00', 'EUR')->allocate(3, 1, 1);

        self::assertSame(
            ['60.00', '20.00', '20.00'],
            array_map(static fn (Money $m): string => $m->amountAsString(), $parts),
        );
    }

    public function testAllocateAcceptsDecimalWeights(): void
    {
        $parts = Money::of('100.00', 'EUR')->allocate('0.5', '0.25', '0.25');

        self::assertSame(
            ['50.00', '25.00', '25.00'],
            array_map(static fn (Money $m): string => $m->amountAsString(), $parts),
        );
    }

    public function testAllocateDistributesScarceMinorUnits(): void
    {
        $parts = Money::of('0.05', 'EUR')->allocateEvenly(3);

        self::assertSame(
            ['0.02', '0.02', '0.01'],
            array_map(static fn (Money $m): string => $m->amountAsString(), $parts),
        );
    }

    public function testAllocateNegativeMirrorsPositive(): void
    {
        $parts = Money::of('-100.00', 'EUR')->allocateEvenly(3);

        self::assertSame(
            ['-33.34', '-33.33', '-33.33'],
            array_map(static fn (Money $m): string => $m->amountAsString(), $parts),
        );
    }

    /**
     * determinismus.md §2 / Fixture-Pflichtfall 4: AfA-Verteilung über
     * 36 Monate ohne Restfehler — Σ Teile = Ausgangsbetrag, immer.
     */
    public function testAllocateOver36MonthsSumsExactly(): void
    {
        $total = Money::of('1000.00', 'EUR');
        $parts = $total->allocateEvenly(36);

        self::assertCount(36, $parts);

        $sum = Money::zero('EUR');
        foreach ($parts as $part) {
            $sum = $sum->add($part);
        }

        self::assertTrue($sum->equals($total));
        // 100000 / 36 = 2777 Rest 28: die ersten 28 Teile bekommen den Mehr-Cent.
        self::assertSame('27.78', $parts[0]->amountAsString());
        self::assertSame('27.78', $parts[27]->amountAsString());
        self::assertSame('27.77', $parts[28]->amountAsString());
        self::assertSame('27.77', $parts[35]->amountAsString());
    }

    public function testAllocateZeroAmountYieldsZeros(): void
    {
        $parts = Money::zero('EUR')->allocateEvenly(3);

        self::assertSame(
            ['0.00', '0.00', '0.00'],
            array_map(static fn (Money $m): string => $m->amountAsString(), $parts),
        );
    }

    public function testAllocateZeroWeightPartsGetNothing(): void
    {
        $parts = Money::of('0.03', 'EUR')->allocate(0, 1, 1);

        self::assertSame(
            ['0.00', '0.02', '0.01'],
            array_map(static fn (Money $m): string => $m->amountAsString(), $parts),
        );
    }

    public function testAllocateRejectsInvalidWeights(): void
    {
        $money = Money::of('1.00', 'EUR');

        $cases = [
            static fn (): array => $money->allocate(),
            static fn (): array => $money->allocate(0, 0),
            static fn (): array => $money->allocate('-1', '2'),
            static fn (): array => $money->allocate('abc'),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('InvalidValue erwartet');
            } catch (InvalidValue) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testJsonShapeMatchesDataFormat(): void
    {
        self::assertSame(
            ['amount' => '1234.56', 'currency' => 'EUR'],
            Money::of('1234.56', 'EUR')->jsonSerialize(),
        );
    }
}
