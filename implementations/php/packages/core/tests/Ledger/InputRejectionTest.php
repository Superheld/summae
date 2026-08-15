<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Ledger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\DomainError;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Substrate\UuidV7IdGenerator;
use Summae\Core\Tenant;

/**
 * Cross-language pin for the `details` payload of a rejected input.
 *
 * The conformance suite compares the error CODE and nothing else, so two implementations can
 * agree on every fixture and still hand a caller different payloads — which is what happened
 * when these guards were built: a rejected object was reported as "[object Object]" in Node and
 * as null here, `true` as "true" against "1". The rule both languages follow now is the one
 * canonical JSON already draws: strings and safe integers are echoed back, everything else is
 * dropped to null rather than rendered by a cast that differs by language.
 *
 * The SAME table lives in the Node input-rejection.test.ts. If one language starts rendering a
 * value differently, that language goes red here.
 */
final class InputRejectionTest extends TestCase
{
    /**
     * The shared table. `validYear` marks the one value that is a perfectly good fiscal year and
     * therefore belongs to the two `kind` guards only.
     *
     * @return array<string, array{mixed, ?string, bool}> [value, expected detail, valid year]
     */
    public static function rejectedValues(): array
    {
        return [
            'a string is echoed back so the caller sees their own typo' => ['bogus', 'bogus', false],
            'a whole number renders the same in both languages' => [123, '123', true],
            'a negative whole number likewise' => [-5, '-5', false],
            // Below: everything a plain cast would have rendered differently.
            'true is "1" here and "true" in Node — dropped' => [true, null, false],
            'false is "" here and "false" in Node — dropped' => [false, null, false],
            'a fractional number is not exactly representable — dropped' => [2028.5, null, false],
            'beyond 2^53 this is no longer an exact integer — dropped' => [1e21, null, false],
            'an object would be "[object Object]" in Node — dropped' => [[], null, false],
            'an array likewise' => [[1, 2], null, false],
            'null carries nothing' => [null, null, false],
        ];
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function rejectedYears(): array
    {
        return self::cases(static fn (array $case): bool => $case[2] === false);
    }

    /**
     * Absent means "no filter" resp. "entries" for the two kinds, so only present-but-wrong applies.
     *
     * @return array<string, array{mixed, ?string}>
     */
    public static function rejectedKinds(): array
    {
        return self::cases(static fn (array $case): bool => $case[0] !== null);
    }

    /**
     * @param callable(array{mixed, ?string, bool}): bool $keep
     *
     * @return array<string, array{mixed, ?string}>
     */
    private static function cases(callable $keep): array
    {
        $selected = [];

        foreach (self::rejectedValues() as $label => $case) {
            if ($keep($case)) {
                $selected[$label] = [$case[0], $case[1]];
            }
        }

        return $selected;
    }

    #[DataProvider('rejectedYears')]
    public function testCreateFiscalYearReportsTheRejectedYear(mixed $value, ?string $detail): void
    {
        $details = $this->rejectionOf(fn () => $this->ops()->execute(
            'createFiscalYear',
            ['year' => $value, 'start' => '2027-01-01', 'end' => '2027-12-31'],
        ));

        self::assertSame($detail, $details['year'] ?? null);
    }

    #[DataProvider('rejectedKinds')]
    public function testOpenItemsReportsTheRejectedKind(mixed $value, ?string $detail): void
    {
        $details = $this->rejectionOf(fn () => $this->ops()->project('openItems', ['kind' => $value]));

        self::assertSame($detail, $details['kind'] ?? null);
    }

    #[DataProvider('rejectedKinds')]
    public function testDatevExportReportsTheRejectedKind(mixed $value, ?string $detail): void
    {
        $details = $this->rejectionOf(
            fn () => $this->ops()->project('datevExport', ['kind' => $value, 'fiscalYear' => 2026]),
        );

        self::assertSame($detail, $details['kind'] ?? null);
    }

    private function ops(): TenantOperations
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');

        return new TenantOperations(Tenant::inMemory(
            'Rejection',
            Currency::of('EUR'),
            $clock,
            new UuidV7IdGenerator($clock),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function rejectionOf(callable $call): array
    {
        try {
            $call();
        } catch (DomainError $e) {
            self::assertSame('E_INPUT_INVALID', $e->errorCode);

            return $e->details;
        }

        self::fail('expected a rejection, the call succeeded');
    }
}
