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

    /**
     * The filing windows assumed when a pack declares none — a **default, not a definition**
     * (SPEC-016). `packPolicy.vatPeriods` overrides it entirely, which is what lets a jurisdiction
     * that files bi-monthly say so without the substrate learning the word.
     *
     * @var list<string>
     */
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
     * substrate is a claim the substrate has no business making — **which is why the pack answers
     * it now** (SPEC-016). `packPolicy.vatPeriods` declares the windows a jurisdiction files in, and
     * a pack that declares them overrides this list entirely: Ireland files bi-monthly and can say
     * so without the substrate learning the word.
     *
     * `self::PERIODS` remains as the answer for a pack that declares nothing. It is a **default,
     * not a definition** — the difference that closes the finding is that no pack is stuck with it.
     * Keeping it is what makes the change additive: an existing pack, an inline bundle and every
     * tenant already in the field behave exactly as before.
     *
     * @param array<mixed> $data {taxationMethod?, smallBusiness?: bool|list, vatPeriod?}
     * @param list<string>|null $vatPeriods what the pack recognises; null = this substrate default
     */
    public static function fromData(array $data, ?array $vatPeriods = null): self
    {
        $method = self::oneOf($data, 'taxationMethod', self::METHODS, 'accrual');
        $periods = $vatPeriods === null || $vatPeriods === [] ? self::PERIODS : $vatPeriods;
        // The fallback for an absent field is the pack's first declared window, which for the
        // substrate default is `monthly`... and that would change the documented default. So: the
        // substrate default keeps `quarterly`, a declaring pack gets its own first entry.
        $fallback = $vatPeriods === null || $vatPeriods === [] ? 'quarterly' : $periods[0];
        $vatPeriod = self::oneOf($data, 'vatPeriod', $periods, $fallback);

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
     * A profile that summae itself stored, rebuilt without re-validating it.
     *
     * Validation belongs at the boundary, and this is not one: the values here were checked when
     * they arrived. Re-checking them on the way *out* of our own store would mean a tenant whose
     * pack later drops a filing window can no longer be opened — a rule change reaching backwards
     * into books that were kept correctly under the old one, which is the opposite of what an
     * append-only ledger promises.
     *
     * @param array<mixed> $data
     */
    public static function restore(array $data): self
    {
        $method = is_string($data['taxationMethod'] ?? null) ? $data['taxationMethod'] : 'accrual';
        $vatPeriod = is_string($data['vatPeriod'] ?? null) ? $data['vatPeriod'] : 'quarterly';

        $segments = [];
        foreach (is_array($data['smallBusiness'] ?? null) ? $data['smallBusiness'] : [] as $segment) {
            if (!is_array($segment) || !is_string($segment['validFrom'] ?? null)) {
                continue;
            }

            $segments[] = [
                'validFrom' => CalendarDate::of($segment['validFrom']),
                'value' => (bool) ($segment['value'] ?? false),
            ];
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
