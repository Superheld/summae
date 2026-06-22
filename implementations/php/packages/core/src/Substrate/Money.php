<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Summae\Core\Substrate\Exception\CurrencyMismatch;
use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * Amount = decimal value + currency, never float (Glossary `money`).
 *
 * Determinism rules (determinismus.md):
 * - §2 rounding: commercial half-up, away from zero at exactly .5
 * - §2 allocate: largest-remainder, tie -> first part in stable
 *   order; invariant Σ parts = original amount, always.
 *
 * The amount internally always lies exactly on the currency scale.
 */
final readonly class Money implements \JsonSerializable, \Stringable
{
    private function __construct(
        public BigDecimal $amount,
        public Currency $currency,
    ) {
    }

    /**
     * Exact amount on the currency scale. More decimal places than the
     * currency allows is an error — nothing is ever silently rounded here.
     */
    public static function of(string $amount, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        try {
            $decimal = BigDecimal::of($amount);
            $scaled = $decimal->toScale($currency->scale);
        } catch (MathException) {
            throw new InvalidValue(sprintf(
                'Invalid amount "%s" for currency %s (scale %d)',
                $amount,
                $currency->code,
                $currency->scale,
            ));
        }

        return new self($scaled, $currency);
    }

    /**
     * Bring the result of a calculation to the currency scale: half-up
     * (determinismus.md §2: 2.225 -> 2.23, -2.345 -> -2.35).
     * The only path on which Money rounds.
     */
    public static function fromCalculation(BigNumber|string $value, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        try {
            $scaled = BigDecimal::of($value)->toScale($currency->scale, RoundingMode::HALF_UP);
        } catch (MathException) {
            throw new InvalidValue(sprintf('Invalid calculation value for currency %s', $currency->code));
        }

        return new self($scaled, $currency);
    }

    public static function zero(Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        return new self(BigDecimal::zero()->toScale($currency->scale), $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount)->toScale($this->currency->scale), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount)->toScale($this->currency->scale), $this->currency);
    }

    public function negate(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    public function abs(): self
    {
        return new self($this->amount->abs(), $this->currency);
    }

    /** -1, 0 oder 1 */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency)
            && $this->amount->compareTo($other->amount) === 0;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    /**
     * Distributes the amount by weights (determinismus.md §2):
     * largest-remainder, tie -> first part. Σ parts = amount, always.
     *
     * Weights: non-negative decimal values (int or string), sum > 0.
     * Negative amounts are distributed as a negated mirror image.
     *
     * @return list<self>
     */
    public function allocate(int|string ...$weights): array
    {
        if ($weights === []) {
            throw new InvalidValue('allocate needs at least one weight');
        }

        if ($this->isNegative()) {
            return array_map(
                static fn (self $part): self => $part->negate(),
                $this->negate()->allocate(...$weights),
            );
        }

        $integerWeights = $this->normalizeWeights(array_values($weights));
        $weightSum = BigInteger::zero();
        foreach ($integerWeights as $weight) {
            $weightSum = $weightSum->plus($weight);
        }

        if ($weightSum->isZero()) {
            throw new InvalidValue('Weight sum must be > 0');
        }

        $scale = $this->currency->scale;
        $totalMinor = $this->amount->withPointMovedRight($scale)->toBigInteger();

        /** @var list<BigInteger> $base */
        $base = [];
        /** @var list<BigInteger> $remainders */
        $remainders = [];
        $assigned = BigInteger::zero();

        foreach ($integerWeights as $weight) {
            [$quotient, $remainder] = $totalMinor->multipliedBy($weight)->quotientAndRemainder($weightSum);
            $base[] = $quotient;
            $remainders[] = $remainder;
            $assigned = $assigned->plus($quotient);
        }

        // Remainder distribution by largest remainder; tie -> smallest index.
        $leftover = $totalMinor->minus($assigned)->toInt();
        $order = range(0, count($base) - 1);
        usort($order, static function (int $a, int $b) use ($remainders): int {
            $byRemainder = $remainders[$b]->compareTo($remainders[$a]);

            return $byRemainder !== 0 ? $byRemainder : $a <=> $b;
        });

        for ($i = 0; $i < $leftover; $i++) {
            $index = $order[$i];
            $base[$index] = $base[$index]->plus(1);
        }

        return array_map(
            fn (BigInteger $minor): self => new self(
                BigDecimal::ofUnscaledValue($minor, $scale),
                $this->currency,
            ),
            array_values($base),
        );
    }

    /**
     * Distribution into n equal parts (collective-item fifths, depreciation monthly rates).
     *
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidValue('allocateEvenly needs at least one part');
        }

        return $this->allocate(...array_fill(0, $parts, 1));
    }

    /** Amount as a string decimal with fixed scale, e.g. "1234.56" (datenformat.md). */
    public function amountAsString(): string
    {
        return (string) $this->amount;
    }

    /** @return array{amount: string, currency: string} */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amountAsString(),
            'currency' => $this->currency->code,
        ];
    }

    public function __toString(): string
    {
        return $this->amountAsString() . ' ' . $this->currency->code;
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw new CurrencyMismatch(sprintf(
                'Currencies do not mix: %s vs. %s',
                $this->currency->code,
                $other->currency->code,
            ));
        }
    }

    /**
     * Bring decimal weights losslessly to integer weights of the same scale.
     *
     * @param list<int|string> $weights
     *
     * @return list<BigInteger>
     */
    private function normalizeWeights(array $weights): array
    {
        $decimals = [];
        $maxScale = 0;

        foreach ($weights as $weight) {
            try {
                $decimal = BigDecimal::of($weight);
            } catch (MathException) {
                throw new InvalidValue(sprintf('Invalid weight "%s"', $weight));
            }

            if ($decimal->isNegative()) {
                throw new InvalidValue('Weights must not be negative');
            }

            $decimals[] = $decimal;
            $maxScale = max($maxScale, $decimal->getScale());
        }

        return array_map(
            static fn (BigDecimal $decimal): BigInteger => $decimal
                ->withPointMovedRight($maxScale)
                ->toBigInteger(),
            $decimals,
        );
    }
}
