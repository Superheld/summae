<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Shared;

use Rechnungswesen\Core\Shared\Exception\InvalidValue;

/**
 * Zonenloses Kalenderdatum (determinismus.md §4): Belegdatum und
 * Buchungsdatum kennen keine Zeitzone — kein UTC-Shift-Risiko.
 * ISO-Format sortiert lexikographisch korrekt.
 */
final readonly class CalendarDate implements \JsonSerializable, \Stringable
{
    private function __construct(
        public string $iso,
    ) {
    }

    public static function of(string $iso): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        if ($parsed === false || $parsed->format('Y-m-d') !== $iso) {
            throw new InvalidValue(sprintf('Kein gültiges Kalenderdatum: "%s"', $iso));
        }

        return new self($iso);
    }

    public function compareTo(self $other): int
    {
        return strcmp($this->iso, $other->iso) <=> 0;
    }

    public function equals(self $other): bool
    {
        return $this->iso === $other->iso;
    }

    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isBetween(self $start, self $end): bool
    {
        return !$this->isBefore($start) && !$this->isAfter($end);
    }

    public function year(): int
    {
        return (int) substr($this->iso, 0, 4);
    }

    public function month(): int
    {
        return (int) substr($this->iso, 5, 2);
    }

    public function lastDayOfMonth(): self
    {
        $date = new \DateTimeImmutable($this->iso);

        return new self($date->modify('last day of this month')->format('Y-m-d'));
    }

    public function firstDayOfNextMonth(): self
    {
        $date = new \DateTimeImmutable($this->iso);

        return new self($date->modify('first day of next month')->format('Y-m-d'));
    }

    public function jsonSerialize(): string
    {
        return $this->iso;
    }

    public function __toString(): string
    {
        return $this->iso;
    }
}
