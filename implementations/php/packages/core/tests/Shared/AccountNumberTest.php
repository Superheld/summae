<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Shared;

use PHPUnit\Framework\TestCase;
use Summae\Core\Shared\AccountNumber;
use Summae\Core\Shared\Exception\InvalidValue;

final class AccountNumberTest extends TestCase
{
    /**
     * determinismus.md §3 / Fixture-Pflichtfall 5: Codepoint-Sortierung,
     * führende Nullen signifikant, keine Locale-Collation.
     */
    public function testCodepointOrderingWithLeadingZeros(): void
    {
        $numbers = array_map(AccountNumber::of(...), ['8400', '0420', '1200']);
        usort($numbers, static fn (AccountNumber $a, AccountNumber $b): int => $a->compareTo($b));

        self::assertSame(
            ['0420', '1200', '8400'],
            array_map(static fn (AccountNumber $n): string => $n->value, $numbers),
        );
    }

    public function testStringComparisonNotNumeric(): void
    {
        // "10" < "9" ist gewollt (String-Vergleich, determinismus.md §3)
        self::assertSame(-1, AccountNumber::of('10')->compareTo(AccountNumber::of('9')));
    }

    public function testLeadingZerosAreSignificant(): void
    {
        self::assertFalse(AccountNumber::of('0420')->equals(AccountNumber::of('420')));
    }

    public function testRejectsEmptyAndWhitespace(): void
    {
        foreach (['', ' 420', "42\t0", str_repeat('9', 65)] as $invalid) {
            try {
                AccountNumber::of($invalid);
                self::fail(sprintf('InvalidValue erwartet für "%s"', $invalid));
            } catch (InvalidValue) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testJsonSerializesAsPlainString(): void
    {
        self::assertSame('"0420"', json_encode(AccountNumber::of('0420'), JSON_THROW_ON_ERROR));
    }
}
