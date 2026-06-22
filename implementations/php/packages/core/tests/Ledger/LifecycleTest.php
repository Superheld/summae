<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Ledger;

use Summae\Core\Substrate\EntryStatus;
use Summae\Core\Substrate\Side;

final class LifecycleTest extends LedgerTestCase
{
    public function testCorrectThenFinalizeThenCorrectFails(): void
    {
        $ledger = $this->tenant->ledger;
        $entry = $ledger->post($this->draft([
            ['4930', 'debit', '240.00'],
            ['1200', 'credit', '240.00'],
        ], entryDate: '2026-01-20'))->entry;

        $corrected = $ledger->correct(['entryId' => $entry->id->value, 'text' => 'Bürobedarf Januar']);
        self::assertSame('Bürobedarf Januar', $corrected->text());
        self::assertSame(EntryStatus::Entered, $corrected->status());

        $count = $ledger->finalize(['finalizeUntil' => '2026-01-31']);
        self::assertSame(1, $count);
        self::assertSame(EntryStatus::Finalized, $entry->status());

        $this->expectDomainError('E_ENTRY_FINALIZED', static fn () => $ledger->correct([
            'entryId' => $entry->id->value,
            'text' => 'darf nicht mehr',
        ]));
    }

    public function testCorrectUnknownEntry(): void
    {
        $this->expectDomainError('E_ENTRY_UNKNOWN', fn () => $this->tenant->ledger->correct([
            'entryId' => '00000000-0000-7000-8000-000000000000',
            'text' => 'gibt es nicht',
        ]));
    }

    public function testReverseIsGeneralReversalWithNegativeAmounts(): void
    {
        $ledger = $this->tenant->ledger;
        $entry = $ledger->post($this->draft([
            ['4930', 'debit', '240.00'],
            ['1200', 'credit', '240.00'],
        ], entryDate: '2026-01-20'))->entry;
        $ledger->finalize(['entryId' => $entry->id->value]);

        $reversal = $ledger->reverse([
            'entryId' => $entry->id->value,
            'entryDate' => '2026-02-03',
            'text' => 'Storno',
        ]);

        self::assertSame(2, $reversal->sequenceNumber);
        self::assertTrue($reversal->reverses?->equals($entry->id) ?? false);
        self::assertTrue($entry->reversedBy()?->equals($reversal->id) ?? false);

        // General reversal: same accounts, same sides, negated amounts.
        $lines = $reversal->lines();
        self::assertSame('4930', $lines[0]->account->value);
        self::assertSame(Side::Debit, $lines[0]->side);
        self::assertSame('-240.00', $lines[0]->money->amountAsString());
        self::assertSame('-240.00', $lines[1]->money->amountAsString());

        $this->expectDomainError('E_ENTRY_ALREADY_REVERSED', static fn () => $ledger->reverse([
            'entryId' => $entry->id->value,
            'entryDate' => '2026-02-04',
        ]));

        // Reversal of the reversal is allowed (api.md).
        $reReversal = $ledger->reverse([
            'entryId' => $reversal->id->value,
            'entryDate' => '2026-02-05',
        ]);
        self::assertSame(3, $reReversal->sequenceNumber);
        self::assertSame('240.00', $reReversal->lines()[0]->money->amountAsString());
    }

    public function testAuditTrailRecordsCorrections(): void
    {
        $ledger = $this->tenant->ledger;
        $entry = $ledger->post($this->draft([
            ['4930', 'debit', '50.00'],
            ['1200', 'credit', '50.00'],
        ]) + ['actor' => 'bruce'])->entry;

        $ledger->correct(['actor' => 'bruce', 'entryId' => $entry->id->value, 'text' => 'Neu']);

        $records = $this->tenant->audit->all();
        self::assertCount(2, $records);
        self::assertSame('created', $records[0]->action);
        self::assertSame('corrected', $records[1]->action);
        self::assertSame('bruce', $records[1]->actor);
        self::assertSame(['text' => ['from' => 'Test', 'to' => 'Neu']], $records[1]->changes);
    }
}
