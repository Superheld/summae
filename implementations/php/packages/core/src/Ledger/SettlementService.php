<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

use Summae\Core\DomainError;
use Summae\Core\Policies\Expansion\Settlement;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Records\OpenItem;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\OpenItemKind;
use Summae\Core\Substrate\SettlementDifferenceKind;
use Summae\Core\Substrate\Side;
use Summae\Core\Substrate\Uuid;

/**
 * Settlement: allocation payment -> open item(s), also partial;
 * always explicit, no FIFO automation (determinismus.md §3).
 * Differences (cash discount/write-off/small difference) per api.md G2 (v0.3).
 *
 * **This service does not post.** It takes an entry the caller has already written, checks the
 * allocation is covered by what that entry moves on the item's account, and records it. So when a
 * difference reduces the consideration of an item that carried tax, nothing here demands that the
 * caller's entry also corrects that tax — and no projection can notice the omission afterwards,
 * because each one computes correctly from whatever is on the journal.
 *
 * That is a deliberate boundary, not an oversight: whether a given reduction changes the taxable
 * base is a question a jurisdiction answers, and the policy kind that would express it has no
 * socket in this core yet. Until it does, the caller owes the correction line. See the ➖ row in
 * docs/gobd-conformance.md §4 and A-13 in the app's obligation list.
 */
final readonly class SettlementService
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
        private OpenItemRepository $openItems,
        private AuditWriter $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<OpenItem> the affected items
     */
    public function settle(array $input): array
    {
        $actor = $this->audit->actorOf($input);
        $entry = Lookups::requireEntry($this->journal, $input['entryId'] ?? null);

        $allocations = is_array($input['allocations'] ?? null) ? array_values($input['allocations']) : [];
        if ($allocations === []) {
            throw new DomainError('E_OPENITEM_UNKNOWN', 'settle without allocations');
        }

        /** @var list<array{item: OpenItem, settlement: Settlement}> $plan */
        $plan = [];
        /** @var array<string, Money> $planned amounts already allocated per item */
        $planned = [];

        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                throw new DomainError('E_OPENITEM_UNKNOWN', 'Allocation is not a structure');
            }

            $openItemId = $allocation['openItemId'] ?? null;
            $item = null;
            if (is_string($openItemId)) {
                try {
                    $item = $this->openItems->byId(Uuid::fromString($openItemId));
                } catch (InvalidValue) {
                    $item = null;
                }
            }

            if ($item === null) {
                throw new DomainError('E_OPENITEM_UNKNOWN', sprintf(
                    'Open item %s does not exist',
                    is_string($openItemId) ? $openItemId : '?',
                ));
            }

            $money = $this->parseSettlementMoney($allocation['money'] ?? null, 'Allocation amount');
            [$differenceMoney, $differenceKind] = $this->parseDifference($allocation['difference'] ?? null, $item);

            // Validate fully first, then apply — no partial state.
            $alreadyPlanned = $planned[$item->id->value] ?? Money::zero($this->baseCurrency);
            if ($money->add($alreadyPlanned)->compareTo($item->remaining()) > 0) {
                throw new DomainError('E_SETTLEMENT_EXCEEDS_ITEM', sprintf(
                    'Allocation %s exceeds remaining amount %s of item %s',
                    $money->amountAsString(),
                    $item->remaining()->subtract($alreadyPlanned)->amountAsString(),
                    $item->id->value,
                ), ['openItemId' => $item->id->value]);
            }

            $planned[$item->id->value] = $money->add($alreadyPlanned);
            $plan[] = [
                'item' => $item,
                'settlement' => new Settlement($entry->id, $money, $entry->entryDate, $differenceMoney, $differenceKind),
            ];
        }

        $this->assertEntryCoversAllocations($entry, $plan);

        $affected = [];

        foreach ($plan as $step) {
            $before = $step['item']->remaining()->amountAsString();
            $step['item']->settle($step['settlement']);
            $this->openItems->save($step['item']);
            $this->audit->record($actor, 'openItem', $step['item']->id, 'settled', [
                'remaining' => ['from' => $before, 'to' => $step['item']->remaining()->amountAsString()],
            ]);
            $affected[] = $step['item'];
        }

        return $affected;
    }

    /**
     * R-1: an allocation may not claim more than the settling entry actually books against the
     * open item's account.
     *
     * The only bound used to be the item's remaining amount, so a 500.00 payment could close a
     * 1190.00 receivable in full. The general ledger then carries a 690.00 receivable the
     * subledger no longer knows about — permanently, and with nothing to point at it — and under
     * cash-basis taxation the VAT return declares tax as collected that never arrived.
     *
     * The bound is the entry's NET REDUCING movement on that account, not its total: a payment
     * with a discount books the full receivable against the receivables account and carries the
     * difference as its own line, so those settlements stay valid. Settlements already recorded
     * against this same entry count against the same budget — otherwise the check could be walked
     * around by settling twice.
     *
     * @param list<array{item: OpenItem, settlement: Settlement}> $plan
     */
    private function assertEntryCoversAllocations(JournalEntry $entry, array $plan): void
    {
        $zero = Money::zero($this->baseCurrency);

        // What this entry moves per account, signed so that a positive value reduces a receivable.
        /** @var array<string, Money> $movement */
        $movement = [];
        foreach ($entry->lines() as $line) {
            $account = $this->accounts->byId($line->accountId);
            if ($account === null) {
                continue;
            }

            $key = $account->number->value;
            $signed = $line->side === Side::Credit ? $line->money : $line->money->negate();
            $movement[$key] = ($movement[$key] ?? $zero)->add($signed);
        }

        /** @var array<string, Money> $claimed */
        $claimed = [];
        $addClaim = function (OpenItem $item, Money $amount) use (&$claimed, $zero): void {
            $account = $this->accountOfOpenItem($item);
            if ($account === null) {
                return;
            }

            $asReduction = $item->kind === OpenItemKind::Payable ? $amount->negate() : $amount;
            $claimed[$account] = ($claimed[$account] ?? $zero)->add($asReduction);
        };

        // Already claimed against this entry by an earlier settle call.
        foreach ($this->openItems->all() as $item) {
            foreach ($item->settlements() as $settlement) {
                if ($settlement->entryId->value === $entry->id->value) {
                    $addClaim($item, $settlement->money);
                }
            }
        }

        foreach ($plan as $step) {
            $addClaim($step['item'], $step['settlement']->money);
        }

        foreach ($claimed as $account => $needed) {
            $available = $movement[$account] ?? $zero;
            // Compared in the reducing direction: `needed` is already signed that way, and so is
            // `available`, because a payable is reduced by a debit and a receivable by a credit.
            if ($needed->abs()->compareTo($available->abs()) > 0 || $needed->isPositive() !== $available->isPositive()) {
                throw new DomainError(
                    'E_SETTLEMENT_EXCEEDS_ENTRY',
                    sprintf(
                        'Allocations against account %s claim %s, but the entry moves %s there',
                        $account,
                        $needed->abs()->amountAsString(),
                        $available->abs()->amountAsString(),
                    ),
                    [
                        'account' => $account,
                        'claimed' => $needed->abs()->amountAsString(),
                        'available' => $available->abs()->amountAsString(),
                    ],
                );
            }
        }
    }

    /** The account an open item sits on — its origin posting's line. */
    private function accountOfOpenItem(OpenItem $item): ?string
    {
        $origin = $this->journal->byId($item->originEntryId);
        $line = $origin?->lines()[$item->originLineIndex] ?? null;
        if ($line === null) {
            return null;
        }

        return $this->accounts->byId($line->accountId)?->number->value;
    }

    private function parseSettlementMoney(mixed $raw, string $label): Money
    {
        $amount = is_array($raw) && is_string($raw['amount'] ?? null) ? $raw['amount'] : null;
        $currency = is_array($raw) && is_string($raw['currency'] ?? null) ? $raw['currency'] : null;

        if ($amount === null || $currency !== $this->baseCurrency->code) {
            throw new InvalidValue(sprintf('%s missing or wrong currency', $label));
        }

        $money = Money::of($amount, $this->baseCurrency);

        if (!$money->isPositive()) {
            throw new InvalidValue(sprintf('%s must be > 0', $label));
        }

        return $money;
    }

    /**
     * @return array{0: ?Money, 1: ?SettlementDifferenceKind}
     */
    private function parseDifference(mixed $raw, OpenItem $item): array
    {
        if ($raw === null) {
            return [null, null];
        }

        if (!is_array($raw)) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'difference is not a structure');
        }

        $kind = SettlementDifferenceKind::tryFrom(is_string($raw['kind'] ?? null) ? $raw['kind'] : '');
        if ($kind === null) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', sprintf(
                'Unknown difference kind "%s"',
                is_string($raw['kind'] ?? null) ? $raw['kind'] : '?',
            ));
        }

        try {
            $money = $this->parseSettlementMoney($raw['money'] ?? null, 'Difference amount');
        } catch (InvalidValue) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'Difference amount invalid (≤ 0 or format)');
        }

        if ($money->compareTo($item->remaining()) > 0) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', sprintf(
                'Difference %s exceeds remaining amount %s',
                $money->amountAsString(),
                $item->remaining()->amountAsString(),
            ));
        }

        return [$money, $kind];
    }
}
