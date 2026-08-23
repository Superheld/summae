<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Brick\Math\BigInteger;
use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * An exact fraction of two integers, always in lowest terms with a positive denominator.
 *
 * It exists for one reason: the simultaneous-equation method of internal cost allocation solves a
 * linear system, and solving one means dividing. Every other number in this core either divides
 * evenly or is distributed with `Money::allocate`, so decimals with a fixed scale have been enough
 * — here they are not. 1/3 has no decimal form, and a solver that rounds mid-computation gives
 * answers that depend on the rounding, which is precisely what byte-identical results across two
 * languages cannot tolerate. Rationals have no such freedom: the arithmetic is exact, so PHP and
 * TypeScript cannot drift even in principle.
 *
 * Money never becomes a Rational and a Rational never becomes Money by rounding on its own — the
 * conversion back happens once, at the end, under the same largest-remainder rule the rest of the
 * core uses.
 */
final class Rational
{
    private function __construct(
        public readonly BigInteger $numerator,
        public readonly BigInteger $denominator,
    ) {
    }

    public static function of(BigInteger|int $numerator, BigInteger|int $denominator = 1): self
    {
        $num = $numerator instanceof BigInteger ? $numerator : BigInteger::of($numerator);
        $den = $denominator instanceof BigInteger ? $denominator : BigInteger::of($denominator);

        if ($den->isZero()) {
            throw new InvalidValue('Rational: denominator must not be zero');
        }

        if ($den->isNegative()) {
            $num = $num->negated();
            $den = $den->negated();
        }

        if ($num->isZero()) {
            return new self(BigInteger::zero(), BigInteger::one());
        }

        $gcd = $num->abs()->gcd($den);

        return new self($num->dividedBy($gcd), $den->dividedBy($gcd));
    }

    public static function zero(): self
    {
        return new self(BigInteger::zero(), BigInteger::one());
    }

    /**
     * A decimal string ("0.25", "-3", "1.5") as an exact fraction. Weights arrive as strings
     * because that is how the data format carries them; nothing here rounds.
     */
    public static function fromDecimalString(string $value): self
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidValue(sprintf('Rational: "%s" is not a decimal number', $value));
        }

        $dot = strpos($value, '.');

        if ($dot === false) {
            return self::of(BigInteger::of($value));
        }

        $decimals = max(0, strlen($value) - $dot - 1);

        return self::of(
            BigInteger::of(str_replace('.', '', $value)),
            BigInteger::of(10)->power($decimals),
        );
    }

    public function add(self $other): self
    {
        return self::of(
            $this->numerator->multipliedBy($other->denominator)
                ->plus($other->numerator->multipliedBy($this->denominator)),
            $this->denominator->multipliedBy($other->denominator),
        );
    }

    public function subtract(self $other): self
    {
        return $this->add($other->negate());
    }

    public function multiply(self $other): self
    {
        return self::of(
            $this->numerator->multipliedBy($other->numerator),
            $this->denominator->multipliedBy($other->denominator),
        );
    }

    public function divide(self $other): self
    {
        if ($other->isZero()) {
            throw new InvalidValue('Rational: division by zero');
        }

        return self::of(
            $this->numerator->multipliedBy($other->denominator),
            $this->denominator->multipliedBy($other->numerator),
        );
    }

    public function negate(): self
    {
        return new self($this->numerator->negated(), $this->denominator);
    }

    public function isZero(): bool
    {
        return $this->numerator->isZero();
    }

    public function isNegative(): bool
    {
        return $this->numerator->isNegative();
    }

    public function compareTo(self $other): int
    {
        return $this->numerator->multipliedBy($other->denominator)
            ->compareTo($other->numerator->multipliedBy($this->denominator));
    }

    /**
     * The largest integer not greater than this fraction — floor, not truncation, so that -0.5
     * becomes -1 and the fractional remainder stays in [0, 1) on both sides of zero. That property
     * is what lets largest-remainder rounding work unchanged for negative amounts, and cost centres
     * do carry negative balances (a credit note, a correction).
     */
    public function floorToBigInteger(): BigInteger
    {
        [$quotient, $remainder] = $this->numerator->quotientAndRemainder($this->denominator);

        return $remainder->isNegative() ? $quotient->minus(1) : $quotient;
    }

    /** This fraction minus its floor: always in [0, 1). */
    public function fractionalPart(): self
    {
        return $this->subtract(self::of($this->floorToBigInteger()));
    }
}
