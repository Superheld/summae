<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Projection;

use PHPUnit\Framework\TestCase;
use Summae\Core\Tenant;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;

/**
 * The index.xml obeys the element ORDER the DTD fixes (F-IO-012).
 *
 * io/gdpdu-data-carrier pins the exact bytes, which guards against drift and proves both languages
 * agree — but a pinned string says nothing about *why* those bytes are right, and a successor
 * fixture could pin a wrong order just as happily. This test states the DTD's content models as the
 * assertions they are, so the reason survives in the repository rather than only in a memo.
 *
 * Initial conformance was established differently and once: on 2026-08-28 this output was validated
 * against the published DTD of the Beschreibungsstandard 1.6, with a negative control — a Table
 * whose Name and Description are swapped is rejected by the validator, so "valid" meant something.
 * What is reproduced here is the part of that DTD this exporter actually exercises.
 *
 * A note for whoever validates by hand and gets a surprise: a strict validator also reports
 * "Content model of Media is not determinist". That is a complaint about the **standard's own** DTD —
 * (Name, Command*, Table*, Command*, AcceptNoTables?) is ambiguous under XML 1.0 §3.2.1 — not about
 * this document, and it does not make the file invalid.
 *
 * Node twin: gdpdu-index-structure.test.ts.
 */
final class GdpduIndexStructureTest extends TestCase
{
    /** @return list<string> */
    private static function lines(): array
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $tenant = Tenant::inMemory('Prüfer GmbH', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));
        $ops = new TenantOperations($tenant);
        $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);
        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        $result = $ops->project('gdpduExport', []);
        $xml = is_string($result['indexXml'] ?? null) ? $result['indexXml'] : '';

        return array_map(trim(...), explode("\n", $xml));
    }

    /**
     * The element names in document order, ignoring indentation and content.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function openTags(array $lines): array
    {
        $tags = [];
        foreach ($lines as $line) {
            if (preg_match('/^<([A-Za-z0-9]+)/', $line, $m) === 1) {
                $tags[] = $m[1];
            }
        }

        return $tags;
    }

    public function testDeclaresTheDocumentTypeAndTheVersionWrittenAgainst(): void
    {
        $lines = self::lines();
        self::assertSame('<?xml version="1.0" encoding="utf-8" standalone="no"?>', $lines[0]);
        self::assertSame('<!DOCTYPE DataSet SYSTEM "gdpdu-01-03-2019.dtd">', $lines[1]);
    }

    public function testDataSetOrder(): void
    {
        $tags = self::openTags(self::lines());
        self::assertSame('DataSet', $tags[0]);
        self::assertLessThan((int) array_search('DataSupplier', $tags, true), (int) array_search('Version', $tags, true));
        self::assertLessThan((int) array_search('Media', $tags, true), (int) array_search('DataSupplier', $tags, true));
    }

    public function testDataSupplierIsNameLocationComment(): void
    {
        $lines = self::lines();
        $start = (int) array_search('<DataSupplier>', $lines, true);
        $end = (int) array_search('</DataSupplier>', $lines, true);
        self::assertSame(
            ['Name', 'Location', 'Comment'],
            self::openTags(array_slice($lines, $start + 1, $end - $start - 1)),
        );
    }

    public function testMediaNamesItselfBeforeAnyTable(): void
    {
        $lines = self::lines();
        $media = (int) array_search('<Media>', $lines, true);
        self::assertStringStartsWith('<Name>', $lines[$media + 1]);
    }

    /**
     * Table (URL, Name?, Description?, Validity?, codepage?, (DecimalSymbol, DigitGroupingSymbol)?,
     * SkipNumBytes?, Range?, Epoch?, (VariableLength | FixedLength)) — the order an importer rejects
     * the file over.
     */
    public function testEveryTableOpensInTheDtdOrder(): void
    {
        $lines = self::lines();
        $seen = 0;
        foreach ($lines as $index => $line) {
            if ($line !== '<Table>') {
                continue;
            }
            ++$seen;
            self::assertSame(
                ['URL', 'Name', 'Description', 'UTF8', 'DecimalSymbol', 'DigitGroupingSymbol', 'VariableLength'],
                self::openTags(array_slice($lines, $index + 1, 7)),
            );
        }
        self::assertGreaterThan(0, $seen);
    }

    public function testVariableLengthPutsDelimitersAndPrimaryKeysFirst(): void
    {
        $lines = self::lines();
        $start = (int) array_search('<VariableLength>', $lines, true);
        $end = (int) array_search('</VariableLength>', $lines, true);
        $tags = array_values(array_filter(
            self::openTags(array_slice($lines, $start + 1, $end - $start - 1)),
            static fn (string $tag): bool => in_array($tag, [
                'ColumnDelimiter', 'RecordDelimiter', 'TextEncapsulator',
                'VariablePrimaryKey', 'VariableColumn', 'ForeignKey',
            ], true),
        ));

        self::assertSame(['ColumnDelimiter', 'RecordDelimiter', 'TextEncapsulator'], array_slice($tags, 0, 3));

        $keys = array_keys($tags, 'VariablePrimaryKey', true);
        self::assertNotSame([], $keys, 'a table without a primary key would leave the importer unable to join');
        $lastKey = 0;
        foreach ($keys as $key) {
            $lastKey = max($lastKey, $key);
        }
        self::assertLessThan((int) array_search('VariableColumn', $tags, true), $lastKey);
    }

    public function testAColumnNamesItselfAndADateNamesItsFormat(): void
    {
        $lines = self::lines();
        $start = (int) array_search('<VariableColumn>', $lines, true);
        self::assertSame(['Name', 'Description'], self::openTags(array_slice($lines, $start + 1, 2)));
        // The standard's default is DD.MM.YYYY; summae writes ISO everywhere, so it must say so.
        self::assertContains('<Format>YYYY-MM-DD</Format>', $lines);
    }

    public function testForeignKeyIsNamesThenReferencesAndComesAfterTheColumns(): void
    {
        $lines = self::lines();
        $start = array_search('<ForeignKey>', $lines, true);
        self::assertIsInt($start, 'journal.csv declares foreign keys to accounts and vouchers');
        $end = (int) array_search('</ForeignKey>', array_slice($lines, $start), true) + $start;
        $tags = self::openTags(array_slice($lines, $start + 1, $end - $start - 1));
        self::assertSame('References', $tags[count($tags) - 1]);
        foreach (array_slice($tags, 0, -1) as $tag) {
            self::assertSame('Name', $tag);
        }
        self::assertGreaterThan((int) array_search('<VariableColumn>', $lines, true), $start);
    }
}
