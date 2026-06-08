<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Runner\Comparator;
use Summae\Runner\PlaceholderBag;

final class ComparatorTest extends TestCase
{
    public function testSubsetIgnoresExtraActualFields(): void
    {
        $diffs = Comparator::diff(
            ['sequenceNumber' => 1],
            ['sequenceNumber' => 1, 'id' => 'abc', 'status' => 'entered'],
            new PlaceholderBag(),
        );

        self::assertSame([], $diffs);
    }

    public function testMissingFieldIsReported(): void
    {
        $diffs = Comparator::diff(['status' => 'entered'], ['id' => 'abc'], new PlaceholderBag());

        self::assertCount(1, $diffs);
        self::assertStringContainsString('status', $diffs[0]);
    }

    public function testScalarMismatchIsStrict(): void
    {
        // "1" (String) ist nicht 1 (int) — Beträge sind Strings, exakt.
        $diffs = Comparator::diff(['n' => '1'], ['n' => 1], new PlaceholderBag());

        self::assertCount(1, $diffs);
    }

    public function testListsCompareByIndexAndLength(): void
    {
        $bag = new PlaceholderBag();

        self::assertSame([], Comparator::diff([['a' => 1]], [['a' => 1, 'b' => 2]], $bag));
        self::assertCount(1, Comparator::diff([['a' => 1]], [['a' => 1], ['a' => 2]], $bag));
    }

    public function testCommentKeysAreDocumentationOnly(): void
    {
        $diffs = Comparator::diff(
            ['comment' => 'Pro Beleg gerundet', 'grossTotal' => ['amount' => '1.18']],
            ['grossTotal' => ['amount' => '1.18', 'currency' => 'EUR']],
            new PlaceholderBag(),
        );

        self::assertSame([], $diffs);
    }

    public function testPlaceholderCapturesThenCompares(): void
    {
        $bag = new PlaceholderBag();

        // Erstes Auftreten: bindet an Ist-Wert.
        self::assertSame([], Comparator::diff(['id' => '$E1'], ['id' => 'uuid-a'], $bag));
        // Gleicher Wert: ok.
        self::assertSame([], Comparator::diff(['entryId' => '$E1'], ['entryId' => 'uuid-a'], $bag));
        // Anderer Wert: Abweichung.
        self::assertCount(1, Comparator::diff(['entryId' => '$E1'], ['entryId' => 'uuid-b'], $bag));
    }
}
