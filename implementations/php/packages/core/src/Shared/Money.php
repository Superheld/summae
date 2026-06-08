<?php

declare(strict_types=1);

namespace Summae\Core\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Summae\Core\Shared\Exception\CurrencyMismatch;
use Summae\Core\Shared\Exception\InvalidValue;

/**
 * Betrag = Dezimalwert + Währung, nie Float (Glossar `money`).
 *
 * Determinismus-Regeln (determinismus.md):
 * - §2 Rundung: kaufmännisch half-up, von Null weg bei genau .5
 * - §2 allocate: Largest-Remainder, Gleichstand -> erster Teil in stabiler
 *   Reihenfolge; Invariante Σ Teile = Ausgangsbetrag, immer.
 *
 * Der Betrag liegt intern immer exakt auf der Währungsskala.
 */
final readonly class Money implements \JsonSerializable, \Stringable
{
    private function __construct(
        public BigDecimal $amount,
        public Currency $currency,
    ) {
    }

    /**
     * Exakter Betrag auf Währungsskala. Mehr Nachkommastellen als die
     * Währung erlaubt sind ein Fehler — hier wird nie still gerundet.
     */
    public static function of(string $amount, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        try {
            $decimal = BigDecimal::of($amount);
            $scaled = $decimal->toScale($currency->scale);
        } catch (MathException) {
            throw new InvalidValue(sprintf(
                'Ungültiger Betrag "%s" für Währung %s (Skala %d)',
                $amount,
                $currency->code,
                $currency->scale,
            ));
        }

        return new self($scaled, $currency);
    }

    /**
     * Ergebnis einer Rechnung auf Währungsskala bringen: half-up
     * (determinismus.md §2: 2.225 -> 2.23, -2.345 -> -2.35).
     * Einziger Weg, auf dem Money rundet.
     */
    public static function fromCalculation(BigNumber|string $value, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        try {
            $scaled = BigDecimal::of($value)->toScale($currency->scale, RoundingMode::HALF_UP);
        } catch (MathException) {
            throw new InvalidValue(sprintf('Ungültiger Rechenwert für Währung %s', $currency->code));
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
     * Verteilt den Betrag nach Gewichten (determinismus.md §2):
     * Largest-Remainder, Gleichstand -> erster Teil. Σ Teile = Betrag, immer.
     *
     * Gewichte: nicht-negative Dezimalwerte (int oder String), Summe > 0.
     * Negative Beträge werden als negiertes Spiegelbild verteilt.
     *
     * @return list<self>
     */
    public function allocate(int|string ...$weights): array
    {
        if ($weights === []) {
            throw new InvalidValue('allocate braucht mindestens ein Gewicht');
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
            throw new InvalidValue('Gewichtssumme muss > 0 sein');
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

        // Restverteilung nach größtem Rest; Gleichstand -> kleinster Index.
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
     * Verteilung in n gleiche Teile (Sammelposten-Fünftel, AfA-Monatsraten).
     *
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidValue('allocateEvenly braucht mindestens einen Teil');
        }

        return $this->allocate(...array_fill(0, $parts, 1));
    }

    /** Betrag als String-Dezimal mit fester Skala, z. B. "1234.56" (datenformat.md). */
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
                'Währungen mischen sich nicht: %s vs. %s',
                $this->currency->code,
                $other->currency->code,
            ));
        }
    }

    /**
     * Dezimalgewichte verlustfrei auf ganzzahlige Gewichte gleicher Skala bringen.
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
                throw new InvalidValue(sprintf('Ungültiges Gewicht "%s"', $weight));
            }

            if ($decimal->isNegative()) {
                throw new InvalidValue('Gewichte dürfen nicht negativ sein');
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
