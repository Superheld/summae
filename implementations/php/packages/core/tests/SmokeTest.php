<?php

declare(strict_types=1);

namespace Summae\Core\Tests;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;
use Summae\Cli\CliPackage;
use Summae\Core\CorePackage;
use Summae\Laravel\SummaeServiceProvider;

/**
 * JOB-000: only proves the scaffold holds — autoloading across all
 * three packages and the decimal dependency. Domain tests from JOB-001.
 */
final class SmokeTest extends TestCase
{
    public function testAllPackagesAutoload(): void
    {
        self::assertSame('0.1.0-dev', CorePackage::VERSION);
        self::assertSame('0.1.0-dev', CliPackage::VERSION);
        self::assertTrue(class_exists(SummaeServiceProvider::class));
    }

    public function testDecimalDependencyRoundsHalfUp(): void
    {
        // Core requirement from determinismus.md §2 — here only as proof
        // that brick/math works correctly in the container.
        $rounded = BigDecimal::of('2.225')->toScale(2, RoundingMode::HALF_UP);

        self::assertSame('2.23', (string) $rounded);
    }
}
