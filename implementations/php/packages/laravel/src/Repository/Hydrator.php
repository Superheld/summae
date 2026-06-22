<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Summae\Core\Ledger\EntryLine;
use Summae\Core\Ledger\Side;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DimensionValue;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Gemeinsame (De-)Serialisierung der JSON-Dokumente des Adapters —
 * exakt die Published-Language-Formen aus datenformat.md.
 */
final class Hydrator
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function money(array $data): Money
    {
        return Money::of(
            is_string($data['amount'] ?? null) ? $data['amount'] : '0',
            Currency::of(is_string($data['currency'] ?? null) ? $data['currency'] : 'EUR'),
        );
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<EntryLine>
     */
    public static function entryLines(array $lines): array
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
                self::money($money),
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
