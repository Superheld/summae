<?php

declare(strict_types=1);

namespace Summae\Core\Partner;

use Summae\Core\DomainError;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Uuid;

/**
 * Partner operations (api.md v0.4): createPartner / updatePartner,
 * both with audit trail (master-data changes are audit-relevant).
 */
final readonly class PartnerService
{
    public function __construct(
        private PartnerRepository $partners,
        private AuditTrail $audit,
        private Clock $clock,
        private IdGenerator $ids,
        /**
         * The chart, so an account link can be checked against it (F-CORE-032).
         *
         * Not optional: a service built without it would validate nothing and say nothing, which is
         * the state this check was added to end.
         */
        private AccountRepository $accounts,
        /**
         * The two places a partner is reachable from the books (F-CORE-040) — needed only by
         * erase(), which must refuse while either still names it.
         *
         * A journal entry never names a partner directly; it reaches one through its voucher, which
         * is why the journal is not consulted here and why an entry cannot orphan a reference this
         * check would miss.
         */
        private VoucherRepository $vouchers,
        private OpenItemRepository $openItems,
    ) {
    }

    /**
     * A partner may only be linked to accounts the books actually carry.
     *
     * Without this a partner could be linked to 9999 in a chart that stops at 3110: the operation
     * succeeded, the link was stored as a list of strings on the aggregate, and nothing ever
     * reported it. That is master data wrong for every reader of the books, not only for the screen
     * that entered it — the same argument that pulled `name` and `kind` in here rather than leaving
     * them to the embedding. The account link was made updatable in that pass and its check was not.
     *
     * Whole-list semantics are untouched: an empty list still clears the link.
     *
     * @param array<string, mixed> $input
     */
    private function validateAccountNumbers(array $input): void
    {
        if (!is_array($input['accountNumbers'] ?? null)) {
            return;
        }

        foreach ($input['accountNumbers'] as $value) {
            if (!is_string($value)) {
                continue;
            }
            if ($this->accounts->byNumber(AccountNumber::of($value)) === null) {
                throw new DomainError(
                    'E_ACCOUNT_UNKNOWN',
                    sprintf('Account %s does not exist in this chart', $value),
                    ['account' => $value],
                );
            }
        }
    }

    /** @param array<string, mixed> $input */
    /**
     * A partner needs a name, and the kinds are the three the manual names (F-CORE-032).
     *
     * Both used to be optional in the widest sense: `name` defaulted to `''`, so a request that
     * forgot it created a nameless partner indistinguishable from the next one and impossible to
     * pick out of a list; `kind` was a plain string, so `custommer` was a partner kind like any
     * other and only surfaced as a category nothing could filter on. An embedding application ended
     * up validating both itself, which is the wrong place: master data that is wrong here is wrong
     * for every reader of the books, not just for the screen that entered it.
     *
     * @param array<string, mixed> $input
     */
    private function validateMasterData(array $input, bool $nameRequired): void
    {
        $name = is_string($input['name'] ?? null) ? $input['name'] : null;
        $nameGiven = array_key_exists('name', $input);
        if (($nameRequired || $nameGiven) && ($name === null || trim($name) === '')) {
            throw new DomainError('E_INPUT_INVALID', 'createPartner: "name" must not be empty', [
                'name' => $input['name'] ?? null,
            ]);
        }

        $kind = $input['kind'] ?? null;
        if ($kind !== null && (!is_string($kind) || PartnerKind::tryFrom($kind) === null)) {
            throw new DomainError('E_INPUT_INVALID', 'partner "kind" must be "customer", "supplier" or "both"', [
                'kind' => $kind,
            ]);
        }
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): Partner
    {
        $this->validateMasterData($input, true);
        $this->validateAccountNumbers($input);

        /** @var list<string> $accountNumbers */
        $accountNumbers = array_values(array_filter(
            is_array($input['accountNumbers'] ?? null) ? $input['accountNumbers'] : [],
            is_string(...),
        ));

        /** @var array<string, mixed> $address */
        $address = is_array($input['address'] ?? null) ? $input['address'] : [];

        $name = is_string($input['name'] ?? null) ? $input['name'] : '';
        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : 'both';

        $partner = new Partner(
            $this->ids->next(),
            $name,
            $kind,
            is_string($input['vatId'] ?? null) ? $input['vatId'] : null,
            is_int($input['paymentTermsDays'] ?? null) ? $input['paymentTermsDays'] : null,
            $accountNumbers,
            $address,
        );

        $this->partners->add($partner);
        // A creation is a change from nothing, written as `from: null` rather than as an empty diff
        // — the idiom vouchers and fiscal years already used. The identifying fields only: the
        // partner's current state is retrievable from the master data, and a trail that copies the
        // object is a second source of truth rather than a history.
        $this->recordAudit($input, 'created', $partner->id, [
            'name' => ['from' => null, 'to' => $name],
            'kind' => ['from' => null, 'to' => $kind],
        ]);

        return $partner;
    }

    /** @param array<string, mixed> $input */
    public function update(array $input): Partner
    {
        $partner = $this->require($input['partnerId'] ?? null);
        // Absent leaves the name alone; present and empty is the same mistake as creating without one.
        $this->validateMasterData($input, false);
        $this->validateAccountNumbers($input);
        $changes = $partner->update($input);

        if ($changes !== []) {
            $this->partners->save($partner);
            $this->recordAudit($input, 'updated', $partner->id, $changes);
        }

        return $partner;
    }

    /**
     * Marks a partner as no longer in use, and takes it back (F-CORE-034).
     *
     * Both directions in one place, like the account lock, because the audit record is the point of
     * the operation and two copies of it would be two chances for one direction to record less.
     *
     * @param array<string, mixed> $input
     */
    public function setStatus(array $input, string $target): Partner
    {
        $partner = $this->require($input['partnerId'] ?? null);
        $before = $partner->status();
        if ($target === 'inactive') {
            $partner->deactivate();
        } else {
            $partner->reactivate();
        }

        if ($before !== $partner->status()) {
            $this->partners->save($partner);
            $this->recordAudit(
                $input,
                $target === 'inactive' ? 'deactivated' : 'reactivated',
                $partner->id,
                ['status' => ['from' => $before, 'to' => $partner->status()]],
            );
        }

        return $partner;
    }

    /**
     * Erase a partner and the trail's records about it (F-CORE-040).
     *
     * **Why this exists next to deactivate(), which reads like it should be enough.** It is not the
     * same question. `inactive` says *we no longer trade with them* and is a state the books keep;
     * erasure says *this record must not be here at all*. The mechanism is the same in every
     * jurisdiction and the reason is not: wherever a retention rule applies it applies to what the
     * books reference, and a partner the books have never referenced falls outside it. So the line
     * this operation draws — referenced or not — is the only line the core knows. Which rule puts a
     * record on which side of it, and under what name, is documented outside the core
     * (docs/gdpr-conformance.md) and never asserted here.
     *
     * **Why it also erases the audit records about the partner.** createPartner writes the name
     * and, if given, the address into `changes`. Removing the partner row while that record stands
     * erases nothing — the personal data simply moves to the place nobody looks. So the records
     * *about this partner* go with it, and a single new record is appended in their place naming
     * the id, the actor and the moment, and carrying **no personal payload**. The trail keeps the
     * fact that an erasure happened, which is what an audit asks of it, and stops keeping what the
     * law says must go.
     *
     * **What it will not touch.** A voucher or an open item naming the partner is a bookkeeping
     * record under retention, and the refusal is unconditional — E_PARTNER_IN_USE, with the counts,
     * so a caller can say *why* rather than only *no*. Nothing here can reach a journal entry.
     *
     * @param array<string, mixed> $input
     *
     * @return array{id: string, erasedAuditRecords: int}
     */
    public function erase(array $input): array
    {
        $partner = $this->require($input['partnerId'] ?? null);

        $vouchers = 0;
        foreach ($this->vouchers->all() as $voucher) {
            if ($voucher->partnerId?->value === $partner->id->value) {
                ++$vouchers;
            }
        }

        $openItems = 0;
        foreach ($this->openItems->all() as $item) {
            if ($item->partnerId?->value === $partner->id->value) {
                ++$openItems;
            }
        }

        if ($vouchers > 0 || $openItems > 0) {
            throw new DomainError(
                'E_PARTNER_IN_USE',
                sprintf(
                    'Business partner %s is referenced by the books and is kept under the retention duty',
                    $partner->id->value,
                ),
                ['partnerId' => $partner->id->value, 'vouchers' => $vouchers, 'openItems' => $openItems],
            );
        }

        $erasedAuditRecords = $this->audit->eraseFor('partner', $partner->id);
        $this->partners->remove($partner->id);
        // Appended after the erasure, never before: the record that documents it must not be one of
        // the records it removes.
        // `existed`, not an empty diff. The published invariant says every record carries before/after
        // values, and the contract test enforces it — which is the right pressure here rather than an
        // exception: what changed IS the existence of the record, and saying so costs nothing and
        // reveals nothing. A diff naming the erased fields would put the name back into the trail,
        // which is the one thing this operation exists to prevent.
        $this->recordAudit($input, 'erased', $partner->id, ['existed' => ['from' => true, 'to' => false]]);

        return ['id' => $partner->id->value, 'erasedAuditRecords' => $erasedAuditRecords];
    }

    public function require(mixed $partnerId): Partner
    {
        $partner = null;

        if (is_string($partnerId) && $partnerId !== '') {
            try {
                $partner = $this->partners->byId(Uuid::fromString($partnerId));
            } catch (InvalidValue) {
                $partner = null;
            }
        }

        return $partner ?? throw new DomainError('E_PARTNER_UNKNOWN', sprintf(
            'Business partner %s does not exist',
            is_string($partnerId) ? $partnerId : '?',
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function recordAudit(array $input, string $action, Uuid $objectId, array $changes): void
    {
        $actor = is_string($input['actor'] ?? null) && $input['actor'] !== '' ? $input['actor'] : 'system';

        $this->audit->append(new AuditRecord(
            $this->ids->next(),
            $this->clock->now(),
            $actor,
            'partner',
            $objectId,
            $action,
            $changes,
        ));
    }
}
