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
 * JOB-000: only proves the scaffold holds — autoloading across all three packages and the decimal
 * dependency.
 *
 * It used to reach the package markers through `assertSame('0.1.0-dev', …)`, which read as a
 * version check and worked as a freeze: the two constants could not be bumped without turning this
 * test red, in a file whose stated subject is autoloading. That is how `summae --version` stayed at
 * the scaffold value for fifteen releases (IMPL-035) — not unguarded, but guarded by a test that
 * was not about it. What this test wants is that the classes load, so that is what it asserts; the
 * versions belong to `ReleaseVersionTest`, which compares them to the changelog.
 */
final class SmokeTest extends TestCase
{
    public function testAllPackagesAutoload(): void
    {
        self::assertTrue(class_exists(CorePackage::class));
        self::assertTrue(class_exists(CliPackage::class));
        self::assertTrue(class_exists(SummaeServiceProvider::class));
    }

    public function testDecimalDependencyRoundsHalfUp(): void
    {
        // Core requirement from determinismus.md §2 — here only as proof
        // that brick/math works correctly in the container.
        $rounded = BigDecimal::of('2.225')->toScale(2, RoundingMode::HalfUp);

        self::assertSame('2.23', (string) $rounded);
    }
}
