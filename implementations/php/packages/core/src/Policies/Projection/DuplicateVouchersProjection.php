<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Records\Voucher;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Side;

/**
 * Documents that look like they were entered twice (F-CORE-044).
 *
 * **The defect this exists for.** `voucherNumber` is a free string with no uniqueness of any kind,
 * and `postVoucher` even substitutes `''` when none is supplied. The same incoming invoice booked
 * twice therefore produces two vouchers, two balanced entries, two open items and two input-tax
 * deductions, and every invariant the library has is satisfied: the entries balance, they carry a
 * voucher, they sit in an open period, the trial balance adds up. Nothing in summae notices, and
 * nothing in the reports looks wrong — the second deduction is simply money claimed twice.
 *
 * **Why a projection and not a refusal.** Duplicate voucher numbers are legitimate across sources:
 * two suppliers may both send their invoice number 1, and a tenant that uses the supplier's number
 * as its own voucher number will meet that in its first year. So the grouping is by *document
 * identity* — the issuer plus the number — and even then the answer is a report, not an error. This
 * follows the line `vatReturn.gapWarnings` draws: name it at the figures, let the application
 * decide. A hard uniqueness rule would be wrong in a way that cannot be worked around, and the one
 * thing worse than a missing check is one that blocks correct bookkeeping.
 *
 * **Deliberately no parameters, and that is a decision rather than an omission.** A date window is
 * the obvious one to offer and the wrong one to have: an invoice entered in December and again in
 * January is exactly the case this projection exists for, and any window on the voucher date hides
 * it at the boundary. `accounts` takes no parameters for the same kind of reason.
 *
 * **Three exclusions, each because including it would produce noise rather than findings:**
 * a voucher with an empty `voucherNumber` (there is nothing to compare — and it is not this
 * projection's business to complain about that); a voucher flagged `recurring` (a Dauerbeleg
 * repeating its number is what the flag means); and, per voucher, entries that are a reversal or
 * have been reversed — `postedTotal` counts only what still moves the books, so a duplicate that
 * has already been corrected reads `0.00` instead of dropping out silently. `stillPosted` counts
 * the vouchers in a group that have a non-zero total, which is the number an application acts on.
 *
 * Substrate, not pack: entering one document twice is wrong in every jurisdiction, and the
 * projection cites no statute.
 */
final readonly class DuplicateVouchersProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private VoucherRepository $vouchers,
        private JournalRepository $journal,
        /** Only for the name — a duplicate list that says `partnerId` and not "Müller GmbH" is read by nobody. */
        private PartnerRepository $partners,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(): array
    {
        $entriesByVoucher = $this->entriesByVoucher();

        /** @var array<string, list<Voucher>> $groups */
        $groups = [];
        foreach ($this->vouchers->all() as $voucher) {
            if ($voucher->voucherNumber === '' || $voucher->recurring) {
                continue;
            }
            $key = self::groupKey($voucher);
            $groups[$key] ??= [];
            $groups[$key][] = $voucher;
        }

        ksort($groups, SORT_STRING);

        $duplicates = [];
        $voucherCount = 0;
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }

            usort($members, static fn (Voucher $a, Voucher $b): int
                => [$a->voucherDate->iso, $a->id->value] <=> [$b->voucherDate->iso, $b->id->value]);

            $rows = [];
            $stillPosted = 0;
            foreach ($members as $voucher) {
                $entries = $entriesByVoucher[$voucher->id->value] ?? [];
                $total = $this->postedTotal($entries);
                if (!$total->isZero()) {
                    ++$stillPosted;
                }
                $rows[] = $this->serialize($voucher, $entries, $total);
            }

            $first = $members[0];
            $duplicates[] = [
                'voucherNumber' => $first->voucherNumber,
                'partnerId' => $first->partnerId?->value,
                'partnerName' => $first->partnerId === null
                    ? null
                    : $this->partners->byId($first->partnerId)?->name(),
                'issuer' => $first->issuer,
                'count' => count($members),
                'stillPosted' => $stillPosted,
                'vouchers' => $rows,
            ];
            $voucherCount += count($members);
        }

        return [
            'count' => count($duplicates),
            'voucherCount' => $voucherCount,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Issuer identity first, so two documents from the same source sort together, then the number,
     * separated by a byte that cannot occur in either — without it, issuer "AB" + number "C" and
     * issuer "A" + number "BC" would be one group. `partnerId` wins over `issuer` when both are
     * present: the master record is the identity, the string is what somebody typed. Vouchers with
     * neither group among themselves — two vouchers "RE-4711" from nowhere in particular are still
     * worth a second look.
     */
    private static function groupKey(Voucher $voucher): string
    {
        $issuer = $voucher->partnerId !== null ? $voucher->partnerId->value : ($voucher->issuer ?? '');

        return $issuer . "\x1f" . $voucher->voucherNumber;
    }

    /**
     * @return array<string, list<JournalEntry>> keyed by voucher id, in journal order
     */
    private function entriesByVoucher(): array
    {
        $byVoucher = [];
        foreach ($this->journal->all() as $entry) {
            $byVoucher[$entry->voucherId->value][] = $entry;
        }

        return $byVoucher;
    }

    /**
     * What the voucher still moves: the debit side of its entries, skipping any entry that is a
     * reversal or has been reversed. A duplicate that was already corrected therefore reads
     * `0.00` and stays visible with its history, instead of disappearing from a list somebody is
     * using to decide whether it was corrected.
     *
     * @param list<JournalEntry> $entries
     */
    private function postedTotal(array $entries): Money
    {
        $total = Money::zero($this->baseCurrency);
        foreach ($entries as $entry) {
            if ($entry->reverses !== null || $entry->reversedBy() !== null) {
                continue;
            }
            foreach ($entry->lines() as $line) {
                if ($line->side === Side::Debit) {
                    $total = $total->add($line->money);
                }
            }
        }

        return $total;
    }

    /**
     * @param list<JournalEntry> $entries
     *
     * @return array{voucherId: string, voucherDate: string, postedTotal: array<string, string>, entries: list<array<string, mixed>>}
     */
    private function serialize(Voucher $voucher, array $entries, Money $total): array
    {
        usort($entries, static fn (JournalEntry $a, JournalEntry $b): int
            => [$a->periodRef->fiscalYear, $a->sequenceNumber] <=> [$b->periodRef->fiscalYear, $b->sequenceNumber]);

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                'entryId' => $entry->id->value,
                'sequenceNumber' => $entry->sequenceNumber,
                'fiscalYear' => $entry->periodRef->fiscalYear,
                'entryDate' => $entry->entryDate->iso,
                'status' => $entry->isFinalized() ? 'finalized' : 'entered',
                'reverses' => $entry->reverses?->value,
                'reversedBy' => $entry->reversedBy()?->value,
            ];
        }

        return [
            'voucherId' => $voucher->id->value,
            'voucherDate' => $voucher->voucherDate->iso,
            'postedTotal' => $total->jsonSerialize(),
            'entries' => $rows,
        ];
    }
}
