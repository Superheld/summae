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
 * JOB-000: beweist nur, dass das Gerüst trägt — Autoloading über alle
 * drei Packages und die Decimal-Abhängigkeit. Fachtests ab JOB-001.
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
        // Kernanforderung aus determinismus.md §2 — hier nur als Beweis,
        // dass brick/math im Container korrekt arbeitet.
        $rounded = BigDecimal::of('2.225')->toScale(2, RoundingMode::HALF_UP);

        self::assertSame('2.23', (string) $rounded);
    }
}
