<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Policies;

use PHPUnit\Framework\TestCase;
use Summae\Core\DomainError;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;

/**
 * The pack's filing windows **replace** the substrate's, they do not extend them (SPEC-016).
 *
 * The fixture `xx-7-pack-declares-filing-periods` proves the half that is visible from outside: a
 * jurisdiction can name a window the core never learned. This proves the other half, which no
 * fixture can reach because it needs a pack that *excludes* something — a pack whose jurisdiction
 * has no quarterly filing must not have quarterly quietly available, or the substrate's list is
 * still the real one and the finding is only half closed.
 *
 * Also pinned: the fallback for an absent `vatPeriod`. The substrate default keeps its documented
 * `quarterly`; a declaring pack gets its own first window instead, because a pack that does not
 * file quarterly should not have quarterly as its default either.
 *
 * The Node twin is `vat-periods-from-pack.test.ts`.
 */
final class VatPeriodsFromPackTest extends TestCase
{
    public function testAPackCanNameAWindowTheSubstrateNeverLearned(): void
    {
        $profile = TaxProfile::fromData(['vatPeriod' => 'bi-monthly'], ['bi-monthly', 'yearly']);

        self::assertSame('bi-monthly', $profile->vatPeriod());
    }

    public function testAPackListReplacesTheSubstrateListRatherThanExtendingIt(): void
    {
        try {
            TaxProfile::fromData(['vatPeriod' => 'quarterly'], ['bi-monthly', 'yearly']);
            self::fail('a window the pack does not declare must be refused, even when the substrate knows it');
        } catch (DomainError $error) {
            self::assertSame('E_INPUT_INVALID', $error->errorCode);
        }
    }

    public function testTheFallbackFollowsWhoeverOwnsTheList(): void
    {
        self::assertSame('quarterly', TaxProfile::fromData([])->vatPeriod());
        self::assertSame('bi-monthly', TaxProfile::fromData([], ['bi-monthly', 'yearly'])->vatPeriod());
    }

    public function testAPackThatDeclaresNothingBehavesExactlyAsBefore(): void
    {
        self::assertSame('yearly', TaxProfile::fromData(['vatPeriod' => 'yearly'], null)->vatPeriod());
        self::assertSame('yearly', TaxProfile::fromData(['vatPeriod' => 'yearly'], [])->vatPeriod());

        try {
            TaxProfile::fromData(['vatPeriod' => 'zweimonatlich'], null);
            self::fail('without a pack list the substrate default still refuses what it does not know');
        } catch (DomainError $error) {
            self::assertSame('E_INPUT_INVALID', $error->errorCode);
        }
    }

    /** A stored profile is rebuilt, never re-judged — a pack that drops a window must not lock books. */
    public function testAStoredProfileIsRestoredWithoutRevalidation(): void
    {
        $restored = TaxProfile::restore(['taxationMethod' => 'cash', 'vatPeriod' => 'bi-monthly', 'smallBusiness' => []]);

        self::assertSame('bi-monthly', $restored->vatPeriod());
        self::assertSame('cash', $restored->taxationMethod());
    }
}
