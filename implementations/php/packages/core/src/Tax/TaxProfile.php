<?php

declare(strict_types=1);

namespace Summae\Core\Tax;

use Summae\Core\Shared\CalendarDate;

/**
 * Steuerliches Mandantenprofil (tax-modell.md Aggregat 2):
 * Versteuerungsart, Kleinunternehmer-Status mit Gültigkeitszeitraum
 * (unterjähriger Wechsel, SF-11), Voranmeldungszeitraum.
 */
final class TaxProfile implements \JsonSerializable
{
    /**
     * @param list<array{validFrom: CalendarDate, value: bool}> $smallBusiness
     */
    private function __construct(
        private readonly string $taxationMethod,
        private array $smallBusiness,
        private readonly string $vatPeriod,
    ) {
    }

    /**
     * @param array<mixed> $data {taxationMethod?, smallBusiness?: bool|list, vatPeriod?}
     */
    public static function fromData(array $data): self
    {
        $method = ($data['taxationMethod'] ?? null) === 'cash' ? 'cash' : 'accrual';
        $vatPeriod = ($data['vatPeriod'] ?? null) === 'monthly' ? 'monthly' : 'quarterly';

        $segments = [];
        $smallBusiness = $data['smallBusiness'] ?? false;

        if (is_bool($smallBusiness)) {
            if ($smallBusiness) {
                $segments[] = ['validFrom' => CalendarDate::of('0001-01-01'), 'value' => true];
            }
        } elseif (is_array($smallBusiness)) {
            foreach ($smallBusiness as $segment) {
                if (!is_array($segment) || !is_string($segment['validFrom'] ?? null)) {
                    continue;
                }

                $segments[] = [
                    'validFrom' => CalendarDate::of($segment['validFrom']),
                    'value' => (bool) ($segment['value'] ?? false),
                ];
            }
        }

        return new self($method, self::sorted($segments), $vatPeriod);
    }

    public static function default(): self
    {
        return new self('accrual', [], 'quarterly');
    }

    public function taxationMethod(): string
    {
        return $this->taxationMethod;
    }

    public function isCashBasis(): bool
    {
        return $this->taxationMethod === 'cash';
    }

    public function vatPeriod(): string
    {
        return $this->vatPeriod;
    }

    public function smallBusinessAt(CalendarDate $date): bool
    {
        $value = false;

        foreach ($this->smallBusiness as $segment) {
            if ($segment['validFrom']->isAfter($date)) {
                break;
            }

            $value = $segment['value'];
        }

        return $value;
    }

    /** Stichtagswechsel; die Rückwirkungsprüfung macht der TaxService. */
    public function setSmallBusiness(CalendarDate $validFrom, bool $value): void
    {
        $segments = array_values(array_filter(
            $this->smallBusiness,
            static fn (array $segment): bool => !$segment['validFrom']->equals($validFrom),
        ));
        $segments[] = ['validFrom' => $validFrom, 'value' => $value];

        $this->smallBusiness = self::sorted($segments);
    }

    /**
     * @param list<array{validFrom: CalendarDate, value: bool}> $segments
     *
     * @return list<array{validFrom: CalendarDate, value: bool}>
     */
    private static function sorted(array $segments): array
    {
        usort($segments, static fn (array $a, array $b): int => $a['validFrom']->compareTo($b['validFrom']));

        return $segments;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'taxationMethod' => $this->taxationMethod,
            'vatPeriod' => $this->vatPeriod,
            'smallBusiness' => array_map(
                static fn (array $segment): array => [
                    'validFrom' => $segment['validFrom']->iso,
                    'value' => $segment['value'],
                ],
                $this->smallBusiness,
            ),
        ];
    }
}
