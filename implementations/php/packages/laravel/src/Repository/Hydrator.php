<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Summae\Core\DomainError;
use Summae\Core\Substrate\EntryLine;
use Summae\Core\Substrate\Side;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DimensionValue;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Shared (de)serialization of the adapter's JSON documents —
 * exactly the published-language forms from datenformat.md.
 */
final class Hydrator
{
    private function __construct()
    {
    }

    /**
     * The store's amount, on the TENANT's scale — and the scale is the tenant's, not the currency's
     * default (IMPL-040).
     *
     * This used to build `Currency::of($code)` with no override, so every amount came back on the
     * ISO default. A tenant whose pack sets `currencyScale: 3` therefore read `"107.501"` as an
     * *unrepresentable* amount and threw a raw `InvalidValue` out of the adapter; a tenant at scale
     * 0 got `"1234"` silently widened to `"1234.00"`. Nothing noticed because no fixture that
     * re-hydrates money runs at a scale other than 2 — SF-15 passes because both runtimes agree,
     * not because anything verifies the amounts, which is exactly what the finding said.
     *
     * @param array<string, mixed> $data
     */
    public static function money(array $data, Currency $currency): Money
    {
        $amount = $data['amount'] ?? null;

        // A malformed document must not take the process down mid-read — zero on the tenant's scale
        // is the documented fallback and stays. A PRESENT amount is a different matter: that is a
        // value somebody wrote, and if it is on the wrong scale we say so instead of reshaping it.
        if (!is_string($amount)) {
            return Money::zero($currency);
        }

        return Money::of(self::assertScale($amount, $currency), $currency);
    }

    /**
     * The amount carries EXACTLY the tenant's decimal places, mandatory zeros included — the
     * canonical form `datenformat.md` § Grundsätze 2 requires of the data format, and the one thing
     * the schema's amount pattern deliberately cannot check, because that pattern is context-free
     * (0–4 places) while the scale is a property of the tenant's pack.
     *
     * Both directions run through here. Reading is where it earns its keep: a store written by one
     * runtime at scale 3 and opened by a tenant at scale 2 is the scenario `E_AMOUNT_SCALE_MISMATCH`
     * was declared for, and until now the code was declared and never raised.
     *
     * `E_ENTRY_INVALID_AMOUNT` keeps the API-input side (`core/post-malformed` pins it, and a
     * fixture is append-only): that code judges an amount a *caller* offered, this one judges an
     * amount already in the books.
     */
    public static function assertScale(string $amount, Currency $currency): string
    {
        $point = strpos($amount, '.');
        $places = $point === false ? 0 : strlen($amount) - $point - 1;

        if ($places !== $currency->scale) {
            throw new DomainError('E_AMOUNT_SCALE_MISMATCH', sprintf(
                'Stored amount "%s" has %d decimal place(s); %s in this tenant requires exactly %d '
                . '(canonical form, mandatory zeros included)',
                $amount,
                $places,
                $currency->code,
                $currency->scale,
            ), ['amount' => $amount, 'expectedScale' => $currency->scale]);
        }

        return $amount;
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<EntryLine>
     */
    public static function entryLines(array $lines, Currency $currency): array
    {
        $result = [];

        foreach ($lines as $line) {
            $dimensions = [];
            foreach (is_array($line['dimensions'] ?? null) ? $line['dimensions'] : [] as $dimension) {
                if (is_array($dimension) && is_string($dimension['type'] ?? null) && is_string($dimension['code'] ?? null)) {
                    $dimensions[] = DimensionValue::of($dimension['type'], $dimension['code']);
                }
            }

            /** @var array<string, mixed>|null $taxTag */
            $taxTag = is_array($line['taxTag'] ?? null) ? $line['taxTag'] : null;
            /** @var array<string, mixed> $money */
            $money = is_array($line['money'] ?? null) ? $line['money'] : [];

            $result[] = new EntryLine(
                Uuid::fromString(is_string($line['accountId'] ?? null) ? $line['accountId'] : ''),
                AccountNumber::of(is_string($line['account'] ?? null) ? $line['account'] : '0'),
                Side::from(is_string($line['side'] ?? null) ? $line['side'] : 'debit'),
                self::money($money, $currency),
                $dimensions,
                $taxTag,
            );
        }

        return $result;
    }

    public static function date(mixed $value): ?CalendarDate
    {
        return is_string($value) && $value !== '' ? CalendarDate::of(substr($value, 0, 10)) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(mixed $json): array
    {
        if (!is_string($json)) {
            return [];
        }

        /** @var array<string, mixed> */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function decodeList(mixed $json): array
    {
        if (!is_string($json)) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function encode(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
