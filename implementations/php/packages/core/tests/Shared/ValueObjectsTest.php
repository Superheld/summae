<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Shared;

use PHPUnit\Framework\TestCase;
use Summae\Core\Shared\DimensionValue;
use Summae\Core\Shared\Exception\InvalidValue;
use Summae\Core\Shared\PeriodRef;
use Summae\Core\Shared\Uuid;
use Summae\Core\Shared\VoucherRef;

final class ValueObjectsTest extends TestCase
{
    public function testPeriodRefComparesChronologically(): void
    {
        $p2026_1 = new PeriodRef(2026, 1);

        self::assertSame(-1, (new PeriodRef(2025, 12))->compareTo($p2026_1));
        self::assertSame(-1, $p2026_1->compareTo(new PeriodRef(2026, 2)));
        self::assertSame(0, $p2026_1->compareTo(new PeriodRef(2026, 1)));
        self::assertTrue($p2026_1->equals(new PeriodRef(2026, 1)));
    }

    public function testPeriodRefValidatesRanges(): void
    {
        $this->expectException(InvalidValue::class);
        new PeriodRef(2026, 0);
    }

    public function testPeriodRefJsonShape(): void
    {
        self::assertSame(
            ['fiscalYear' => 2026, 'period' => 1],
            (new PeriodRef(2026, 1))->jsonSerialize(),
        );
    }

    public function testDimensionValueShape(): void
    {
        $dimension = DimensionValue::of('costCenter', '100');

        self::assertSame(['type' => 'costCenter', 'code' => '100'], $dimension->jsonSerialize());
        self::assertTrue($dimension->equals(DimensionValue::of('costCenter', '100')));
        self::assertFalse($dimension->equals(DimensionValue::of('costObject', '100')));
    }

    public function testDimensionValueRejectsEmpty(): void
    {
        $this->expectException(InvalidValue::class);
        DimensionValue::of('', '100');
    }

    public function testVoucherRefWrapsUuid(): void
    {
        $uuid = Uuid::v7();
        $ref = VoucherRef::of($uuid);

        self::assertTrue($ref->equals(VoucherRef::of($uuid->value)));
        self::assertSame($uuid->value, (string) $ref);
    }
}
