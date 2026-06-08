<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Shared;

use PHPUnit\Framework\TestCase;
use Summae\Core\Shared\Exception\InvalidValue;
use Summae\Core\Shared\FixedClock;
use Summae\Core\Shared\Uuid;

final class UuidTest extends TestCase
{
    public function testV7HasVersionAndVariantBits(): void
    {
        $uuid = Uuid::v7();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid->value,
        );
    }

    public function testV7IsTimeOrderedAsString(): void
    {
        $clock = FixedClock::at('2026-06-07T12:00:00.000+02:00');
        $earlier = Uuid::v7($clock);

        $clock->advanceMilliseconds(2);
        $later = Uuid::v7($clock);

        self::assertSame(-1, $earlier->compareTo($later));
    }

    public function testV7EncodesClockTimestamp(): void
    {
        // 2020-01-01T00:00:00Z = 1577836800000 ms = 0x16f5e66e800
        $uuid = Uuid::v7(FixedClock::at('2020-01-01T00:00:00.000+00:00'));

        self::assertStringStartsWith('016f5e66-e800-7', $uuid->value);
    }

    public function testFromStringNormalizesCase(): void
    {
        $uuid = Uuid::fromString('0190A1B2-0000-7000-8000-000000000001');

        self::assertSame('0190a1b2-0000-7000-8000-000000000001', $uuid->value);
    }

    public function testFromStringRejectsGarbage(): void
    {
        $this->expectException(InvalidValue::class);
        Uuid::fromString('nicht-eine-uuid');
    }
}
