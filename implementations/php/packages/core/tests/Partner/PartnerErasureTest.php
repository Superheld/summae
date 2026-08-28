<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Partner;

use PHPUnit\Framework\TestCase;
use Summae\Core\DomainError;
use Summae\Core\InMemory\InMemoryAuditTrail;
use Summae\Core\InMemory\InMemoryPartnerRepository;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Substrate\Uuid;

/**
 * What the `partner-erasure` fixture cannot reach, in both languages (F-CORE-040).
 *
 * The fixture pins the behaviour an embedding sees: the operation succeeds, the refusal carries the
 * right code, the trail keeps one record. Two things sit below that surface and are asserted here,
 * because a fixture's error expectation is a code and nothing else:
 *
 * 1. The refusal's `details`. E_PARTNER_IN_USE carries `vouchers` and `openItems` so an application
 *    can tell a data subject *what* keeps the record rather than only that something does. A refusal
 *    that says no without a reason pushes the operator into guessing, and guessing about a retention
 *    basis is the thing this whole area cannot afford.
 * 2. eraseFor() is selective. It must take the records about *one* object and leave every other
 *    record standing — including records of the same type about a different partner, and records of
 *    a different type that happen to share nothing but the trail. An erasure that took too much
 *    would destroy bookkeeping history to satisfy a privacy request, which is the failure mode in
 *    the opposite direction and the more expensive one.
 *
 * Node twin: partner-erasure.test.ts.
 */
final class PartnerErasureTest extends TestCase
{
    private const PARTNER_A = '01920000-0000-7000-8000-0000000000a1';
    private const PARTNER_B = '01920000-0000-7000-8000-0000000000b2';
    private const ENTRY = '01920000-0000-7000-8000-0000000000e3';

    private function record(string $objectType, string $objectId, string $action): AuditRecord
    {
        return new AuditRecord(
            Uuid::fromString('01920000-0000-7000-8000-00000000000f'),
            // With an argument, never argless: the determinism boundary forbids reaching for the
            // wall clock, not constructing a stated moment.
            new \DateTimeImmutable('2026-03-01T10:00:00.000Z'),
            'bruce',
            $objectType,
            Uuid::fromString($objectId),
            $action,
            ['existed' => ['from' => null, 'to' => true]],
        );
    }

    public function testErasesOnlyTheRecordsAboutThatObjectAndReportsHowMany(): void
    {
        $trail = new InMemoryAuditTrail();
        $trail->append($this->record('partner', self::PARTNER_A, 'created'));
        $trail->append($this->record('partner', self::PARTNER_A, 'updated'));
        $trail->append($this->record('partner', self::PARTNER_B, 'created'));
        $trail->append($this->record('journalEntry', self::ENTRY, 'created'));

        self::assertSame(2, $trail->eraseFor('partner', Uuid::fromString(self::PARTNER_A)));

        // The erased records leave a shell behind rather than a hole: the row is what carries the
        // hash chain's link, and deleting it would break the chain at the successor for good —
        // every later verification would then report a manipulation that never happened. Nothing
        // about the partner survives in the shell; `redacted` is a reserved objectType, so no filter
        // about a real object can reach it.
        $left = array_map(
            static fn (AuditRecord $entry): string => $entry->objectType . '/' . $entry->action,
            $trail->all(),
        );
        self::assertSame(
            ['redacted/redacted', 'redacted/redacted', 'partner/created', 'journalEntry/created'],
            $left,
        );
        foreach ($trail->all() as $entry) {
            if ($entry->isRedacted()) {
                self::assertSame('redacted', $entry->actor);
            }
        }
    }

    public function testKeepsTheChainVerifiableAcrossTheErasure(): void
    {
        $trail = new InMemoryAuditTrail();
        $trail->append($this->record('partner', self::PARTNER_A, 'created'));
        $trail->append($this->record('partner', self::PARTNER_B, 'created'));
        $trail->append($this->record('journalEntry', self::ENTRY, 'created'));
        $before = array_map(static fn (AuditRecord $e): ?string => $e->recordHash, $trail->all());

        $trail->eraseFor('partner', Uuid::fromString(self::PARTNER_A));

        // Both hashes survive the erasure, so every link still resolves. That is the whole reason
        // the shell exists — and the honest limit is that a shell's CONTENT can no longer be
        // verified against its hash, because there is no content left. If it could, the erasure
        // would not be one.
        $after = $trail->all();
        self::assertSame($before, array_map(static fn (AuditRecord $e): ?string => $e->recordHash, $after));
        self::assertSame($before[0], $after[1]->previousRecordHash);
        self::assertSame($before[1], $after[2]->previousRecordHash);
    }

    public function testErasesNothingAndSaysSoWhenTheObjectHasNoRecords(): void
    {
        $trail = new InMemoryAuditTrail();
        $trail->append($this->record('partner', self::PARTNER_B, 'created'));

        self::assertSame(0, $trail->eraseFor('partner', Uuid::fromString(self::PARTNER_A)));
        self::assertCount(1, $trail->all());
    }

    /**
     * The type is part of the identity, not decoration. Ids are UUIDs and will not collide in
     * practice — but "will not collide in practice" is not the argument a deletion should rest on.
     */
    public function testDoesNotMatchARecordOfAnotherTypeCarryingTheSameId(): void
    {
        $trail = new InMemoryAuditTrail();
        $trail->append($this->record('voucher', self::PARTNER_A, 'created'));

        self::assertSame(0, $trail->eraseFor('partner', Uuid::fromString(self::PARTNER_A)));
        self::assertCount(1, $trail->all());
    }

    public function testRemovingAPartnerThatIsNotThereIsNotAnError(): void
    {
        $partners = new InMemoryPartnerRepository();

        self::assertNull($partners->byId(Uuid::fromString(self::PARTNER_A)));
        $partners->remove(Uuid::fromString(self::PARTNER_A));
        self::assertSame([], $partners->all());
    }

    public function testPartnerInUseCarriesDetailsThatNameWhatKeepsTheRecord(): void
    {
        // Constructed directly: the wiring is the fixture's job, the payload shape is this test's.
        $error = new DomainError('E_PARTNER_IN_USE', 'kept under the retention duty', [
            'partnerId' => self::PARTNER_A,
            'vouchers' => 1,
            'openItems' => 2,
        ]);

        self::assertSame('E_PARTNER_IN_USE', $error->errorCode);
        self::assertSame(
            ['partnerId' => self::PARTNER_A, 'vouchers' => 1, 'openItems' => 2],
            $error->details,
        );
    }
}
