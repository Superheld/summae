<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Substrate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\CalendarDate;

/**
 * `CalendarDate` is substrate: frozen, jurisdiction-free, and bound by the top quality
 * policy — the same string must be accepted or rejected identically in every language.
 *
 * It was not. Node validated through `Date.UTC(year, …)`, which maps years 0–99 onto
 * 1900+year (a JavaScript legacy rule), so `0000-01-01` … `0099-12-31` failed the
 * round-trip check while PHP's `DateTimeImmutable` accepted them. The divergence sat in
 * the layer that is supposed to be the most stable of all, and it surfaced only because
 * a missing `year` parameter made a projection build `0000-01-01`.
 *
 * **The SAME two tables live in the Node `calendar-date.test.ts`.** If one language starts
 * accepting or rejecting a value the other does not, that language's test goes red.
 */
final class CalendarDateTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function acceptedProvider(): array
    {
        return self::cases([
            '0000-01-01', // year zero — the JS 0–99 quirk used to reject this
            '0001-01-01',
            '0099-12-31',
            '0100-01-01',
            '1900-01-01',
            '2000-02-29', // divisible by 400 -> leap
            '2024-02-29', // divisible by 4 -> leap
            '2026-01-01',
            '2026-12-31',
            '9999-12-31',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function rejectedProvider(): array
    {
        return self::cases([
            '0000-00-01', // month 0
            '0050-02-29', // year 50 is not a leap year
            '0100-02-29', // divisible by 100, not 400 -> not leap
            '1900-02-29', // the classic: not a leap year
            '2026-02-29', // not a leap year
            '2026-02-30',
            '2026-04-31',
            '2026-13-01',
            '2026-00-10',
            '2026-01-00',
            '2026-01-32',
            '26-01-01', // two-digit year
            '2026-1-1', // unpadded
            '2026-01-01T00:00:00Z', // a timestamp is not a calendar date
            '+2026-01-01',
            '2026/01/01',
            '',
            ' 2026-01-01',
        ]);
    }

    /**
     * @param list<string> $values
     * @return array<string, array{string}>
     */
    private static function cases(array $values): array
    {
        $cases = [];
        foreach ($values as $value) {
            $cases[$value === '' ? '(empty)' : $value] = [$value];
        }

        return $cases;
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptsAndRoundTrips(string $value): void
    {
        self::assertSame($value, CalendarDate::of($value)->iso);
    }

    #[DataProvider('rejectedProvider')]
    public function testRejects(string $value): void
    {
        $this->expectException(\Throwable::class);
        CalendarDate::of($value);
    }

    /** @return array<string, array{string, string, string}> */
    public static function monthArithmeticProvider(): array
    {
        return [
            'in the old 0-99 band' => ['0050-02-10', '0050-02-28', '0050-03-01'],
            'leap February' => ['2024-02-10', '2024-02-29', '2024-03-01'],
            'ordinary February' => ['2026-02-10', '2026-02-28', '2026-03-01'],
            '30-day month' => ['2026-04-05', '2026-04-30', '2026-05-01'],
            'year rollover' => ['2026-12-05', '2026-12-31', '2027-01-01'],
            'upper bound' => ['9999-11-02', '9999-11-30', '9999-12-01'],
        ];
    }

    #[DataProvider('monthArithmeticProvider')]
    public function testMonthArithmetic(string $from, string $last, string $next): void
    {
        self::assertSame($last, CalendarDate::of($from)->lastDayOfMonth()->iso);
        self::assertSame($next, CalendarDate::of($from)->firstDayOfNextMonth()->iso);
    }

    /** @return array<string, array{string, string, int}> */
    public static function dayDifferenceProvider(): array
    {
        // The same table is in the Node calendar-date.test.ts. `daysSince` is what makes the
        // finalization deadline observable (F-CORE-027), so a one-day drift between the
        // languages would show up as a different number in the same audit report.
        return [
            'same day' => ['2026-03-16', '2026-03-16', 0],
            'two days' => ['2026-03-16', '2026-03-14', 2],
            'across months' => ['2026-03-16', '2026-02-01', 43],
            'year boundary' => ['2026-01-01', '2025-12-31', 1],
            'across a leap day' => ['2024-03-01', '2024-02-28', 2],
            'no leap day' => ['2023-03-01', '2023-02-28', 1],
            '2000 IS a leap year' => ['2000-03-01', '2000-02-28', 2],
            '1900 is NOT a leap year' => ['1900-03-01', '1900-02-28', 1],
            'the old 0-99 band' => ['0050-03-01', '0050-02-01', 28],
            'two millennia' => ['2026-01-01', '0001-01-01', 739616],
            'negative when earlier' => ['2026-02-01', '2026-03-16', -43],
        ];
    }

    #[DataProvider('dayDifferenceProvider')]
    public function testDayDifference(string $later, string $earlier, int $days): void
    {
        self::assertSame($days, CalendarDate::of($later)->daysSince(CalendarDate::of($earlier)));
    }
}
