<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Substrate;

use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Exception\InvalidValue;

final class AccountNumberTest extends TestCase
{
    /**
     * determinismus.md §3 / mandatory fixture case 5: codepoint sorting,
     * leading zeros significant, no locale collation.
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
        // "10" < "9" is intended (string comparison, determinismus.md §3)
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
                self::fail(sprintf('InvalidValue expected for "%s"', $invalid));
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
