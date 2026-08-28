<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

use Summae\Core\DomainError;
use Summae\Core\Composition\TenantConfigStore;
use Summae\Core\Policies\Constraint\DimensionRegistry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\AccountStatus;
use Summae\Core\Substrate\AccountSubtype;
use Summae\Core\Substrate\AccountType;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Uuid;

/**
 * Chart-of-accounts administration: creating, locking and bulk-importing accounts. Setup, not
 * bookkeeping — it touches no journal, no period and no open item, which is why it is the
 * cleanest cut out of the orchestrator.
 */
final readonly class ChartAdminService
{
    public function __construct(
        private AccountRepository $accounts,
        private IdGenerator $ids,
        private AuditWriter $audit,
        private ?DimensionRegistry $dimensions = null,
        // A dimension has no id of its own, so the audit record names the tenant — the same shape the
        // tax profile and the allocation scheme use for configuration that exists once per tenant.
        private ?Uuid $tenantId = null,
        /** Where a declared type or value is kept, so it outlives this object (SPEC-015). */
        private ?TenantConfigStore $configStore = null,
    ) {
    }

    /**
     * Declares a dimension type. Master data, like an account: `costCenter`, `project`, `segment`.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function defineDimensionType(array $input): array
    {
        $actor = $this->audit->actorOf($input);
        $code = is_string($input['code'] ?? null) ? $input['code'] : '';

        $this->requireDimensions()->defineType($code);

        if ($this->tenantId !== null) {
            $this->audit->record($actor, 'dimensionType', $this->tenantId, 'created', [
                'code' => ['from' => null, 'to' => $code],
            ]);
        }
        $this->persistDimensions();

        return ['code' => $code];
    }

    /**
     * Declares a value of an existing dimension type.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function defineDimensionValue(array $input): array
    {
        $actor = $this->audit->actorOf($input);
        $typeCode = is_string($input['type'] ?? null) ? $input['type'] : '';
        $code = is_string($input['code'] ?? null) ? $input['code'] : '';

        $this->requireDimensions()->defineValue($typeCode, $code);

        if ($this->tenantId !== null) {
            $this->audit->record($actor, 'dimensionValue', $this->tenantId, 'created', [
                'type' => ['from' => null, 'to' => $typeCode],
                'code' => ['from' => null, 'to' => $code],
            ]);
        }
        $this->persistDimensions();

        return ['type' => $typeCode, 'code' => $code];
    }

    /**
     * The registry is stored whole rather than as a delta: it is a small set, and a whole-value
     * write cannot drift from the object it describes the way an append log can.
     */
    private function persistDimensions(): void
    {
        if ($this->configStore === null || $this->dimensions === null) {
            return;
        }

        $data = $this->dimensions->toData();
        $this->configStore->rememberDimensions($data['types'], $data['values']);
    }

    private function requireDimensions(): DimensionRegistry
    {
        return $this->dimensions ?? throw new DomainError(
            'E_DIMENSION_INVALID',
            'this tenant was built without a dimension registry',
            ['type' => null],
        );
    }

    /**
     * What an account's creation records (F-CORE-014).
     *
     * A creation is a change from nothing, so it is written as `from: null` rather than as an empty
     * diff. That was already the idiom for vouchers, fiscal years, dimensions and costing runs;
     * accounts, postings and partners recorded nothing at all, which made the published invariant
     * ("actor, timestamp, object and before/after values") true of some records and not others.
     * The identifying fields only — the account's current state is retrievable from `accounts`, and
     * a trail that copies the object is a second source of truth, not a history.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function creationDiff(Account $account): array
    {
        $changes = [
            'number' => ['from' => null, 'to' => $account->number->value],
            'name' => ['from' => null, 'to' => $account->name],
            'type' => ['from' => null, 'to' => $account->type->value],
        ];

        if ($account->subtype !== null) {
            $changes['subtype'] = ['from' => null, 'to' => $account->subtype];
        }

        // Only when set, like `subtype`: a window is the exception, and a trail that records two
        // nulls on every account creation says nothing while costing every reader a line.
        if ($account->validFrom !== null) {
            $changes['validFrom'] = ['from' => null, 'to' => $account->validFrom->iso];
        }

        if ($account->validTo !== null) {
            $changes['validTo'] = ['from' => null, 'to' => $account->validTo->iso];
        }

        return $changes;
    }

    /** @param array<string, mixed> $input */
    public function createAccount(array $input): Account
    {
        $actor = $this->audit->actorOf($input);
        $account = $this->buildAccount($input);

        if ($this->accounts->byNumber($account->number) !== null) {
            throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', sprintf(
                'Account number %s is already taken',
                $account->number->value,
            ), ['number' => $account->number->value]);
        }

        $this->accounts->add($account);
        $this->audit->record($actor, 'account', $account->id, 'created', self::creationDiff($account));

        return $account;
    }

    /** @param array<string, mixed> $input */
    public function lockAccount(array $input): Account
    {
        return $this->setAccountStatus($input, AccountStatus::Locked);
    }

    /**
     * The counterpart to lockAccount — see Account::unlock() for why it is core mechanism.
     *
     * @param array<string, mixed> $input
     */
    public function unlockAccount(array $input): Account
    {
        return $this->setAccountStatus($input, AccountStatus::Active);
    }

    /**
     * Both directions in one place: the audit record is the whole point of the operation, and two
     * copies of it would be two chances for one direction to record less than the other.
     *
     * @param array<string, mixed> $input
     */
    private function setAccountStatus(array $input, AccountStatus $target): Account
    {
        $actor = $this->audit->actorOf($input);
        $number = is_string($input['number'] ?? null) ? $input['number'] : '';
        $account = $this->accounts->byNumber(AccountNumber::of($number));

        if ($account === null) {
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf('Account %s does not exist', $number), ['number' => $number]);
        }

        $before = $account->status()->value;
        if ($target === AccountStatus::Locked) {
            $account->lock();
        } else {
            $account->unlock();
        }
        $this->accounts->save($account);
        $this->audit->record($actor, 'account', $account->id, $target === AccountStatus::Locked ? 'locked' : 'unlocked', [
            'status' => ['from' => $before, 'to' => $account->status()->value],
        ]);

        return $account;
    }

    /**
     * Chart-of-accounts import (DATEV-compatible rows): atomic — validate
     * everything first, then create.
     *
     * @param array<string, mixed> $input
     *
     * @return int number of imported accounts
     */
    public function importChartOfAccounts(array $input): int
    {
        $actor = $this->audit->actorOf($input);
        $rows = $input['rows'] ?? null;

        if (!is_array($rows) || $rows === []) {
            throw new DomainError('E_COA_FORMAT_INVALID', 'Import without rows');
        }

        $accounts = [];
        $numbers = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                throw new DomainError('E_COA_FORMAT_INVALID', sprintf('Row %d is not a structure', $index));
            }

            try {
                $account = $this->buildAccount($row);
            } catch (DomainError) {
                throw new DomainError('E_COA_FORMAT_INVALID', sprintf('Row %d is not parsable', $index), ['row' => $index]);
            }

            if (isset($numbers[$account->number->value]) || $this->accounts->byNumber($account->number) !== null) {
                throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', sprintf(
                    'Account number %s is already taken',
                    $account->number->value,
                ), ['number' => $account->number->value]);
            }

            $numbers[$account->number->value] = true;
            $accounts[] = $account;
        }

        foreach ($accounts as $account) {
            $this->accounts->add($account);
            $this->audit->record($actor, 'account', $account->id, 'created', self::creationDiff($account));
        }

        return count($accounts);
    }

    /** @param array<mixed> $input */
    private function buildAccount(array $input): Account
    {
        $number = $input['number'] ?? null;
        $name = $input['name'] ?? null;
        $type = AccountType::tryFrom(is_string($input['type'] ?? null) ? $input['type'] : '');

        if (!is_string($number) || $number === '' || !is_string($name) || $name === '' || $type === null) {
            throw new DomainError('E_COA_FORMAT_INVALID', 'Account needs number, name and a valid type');
        }

        $subtype = is_string($input['subtype'] ?? null) ? $input['subtype'] : null;
        if ($subtype !== null && !AccountSubtype::isKnown($subtype)) {
            // Closed repertoire: an unknown subtype used to be stored and then read by nobody, so
            // the account looked annotated and behaved like an unannotated one.
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'Account %s: unknown subtype "%s"',
                $number,
                $subtype,
            ), ['number' => $number, 'subtype' => $subtype, 'known' => AccountSubtype::all()]);
        }
        $status = ($input['status'] ?? null) === AccountStatus::Locked->value
            ? AccountStatus::Locked
            : AccountStatus::Active;

        $validFrom = is_string($input['validFrom'] ?? null) ? CalendarDate::of($input['validFrom']) : null;
        $validTo = is_string($input['validTo'] ?? null) ? CalendarDate::of($input['validTo']) : null;
        if ($validFrom !== null && $validTo !== null && $validTo->isBefore($validFrom)) {
            // A window that closes before it opens accepts no posting at all, so the account would
            // be created dead and every later refusal would name the date rather than the cause.
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'Account %s: validTo %s lies before validFrom %s',
                $number,
                $validTo->iso,
                $validFrom->iso,
            ), ['number' => $number, 'validFrom' => $validFrom->iso, 'validTo' => $validTo->iso]);
        }

        return new Account(
            $this->ids->next(),
            AccountNumber::of($number),
            $name,
            $type,
            $subtype,
            $status,
            $validFrom,
            $validTo,
        );
    }
}
