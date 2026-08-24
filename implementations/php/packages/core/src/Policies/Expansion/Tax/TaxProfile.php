<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Summae\Core\DomainError;
use Summae\Core\Substrate\CalendarDate;

/**
 * Tenant tax profile (tax-modell.md aggregate 2): taxation method,
 * small-business status with validity period (mid-year change, SF-11),
 * VAT filing period.
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

    /** @var list<string> */
    private const array METHODS = ['accrual', 'cash'];

    /** @var list<string> */
    private const array PERIODS = ['monthly', 'quarterly', 'yearly'];

    /**
     * The two documented sets, refused rather than approximated (F-TAX-003).
     *
     * `taxationMethod` used to be `=== 'cash' ? 'cash' : 'accrual'` and `vatPeriod`
     * `=== 'monthly' ? 'monthly' : 'quarterly'`, so a typo, a `null` or an array all arrived as a
     * valid-looking profile that books differently, and nothing said so. This is not a value object
     * built from a trusted internal source: `fromData` is fed from an embedding's configuration
     * file, which is exactly where a typo lives — and the value it decides is whether VAT falls due
     * on invoice or on payment.
     *
     * **The two sets are not the same kind of thing, and only one of them is safely here.**
     * `taxationMethod` is substrate mechanism: accrual and cash are the two ways this engine can
     * time a tax liability, and it implements both. `vatPeriod` is a *label* — it records which
     * window a tenant files in and selects nothing (`vatReturn` takes its own window). Which filing
     * windows exist is a question jurisdictions answer differently, so a closed list of them in the
     * substrate is a claim the substrate has no business making. `yearly` is added here because the
     * previous list was wrong in a way that lost data silently, not because the list is now right.
     * Open, with the reasoning: SPEC-016.
     *
     * @param array<mixed> $data {taxationMethod?, smallBusiness?: bool|list, vatPeriod?}
     */
    public static function fromData(array $data): self
    {
        $method = self::oneOf($data, 'taxationMethod', self::METHODS, 'accrual');
        $vatPeriod = self::oneOf($data, 'vatPeriod', self::PERIODS, 'quarterly');

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

    /**
     * Absent keeps the documented default; anything else must be one of the documented values.
     *
     * @param array<mixed> $data
     * @param list<string> $allowed
     */
    private static function oneOf(array $data, string $field, array $allowed, string $fallback): string
    {
        if (!array_key_exists($field, $data)) {
            return $fallback;
        }

        $value = $data[$field];
        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }

        throw new DomainError(
            'E_INPUT_INVALID',
            sprintf('taxProfile.%s must be one of "%s"', $field, implode('", "', $allowed)),
            [$field => is_string($value) ? $value : ($value === null ? null : get_debug_type($value))],
        );
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

    /** Cutoff-date change; the retroactivity check is done by the TaxService. */
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
