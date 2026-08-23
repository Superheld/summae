<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

use Summae\Core\DomainError;
use Summae\Core\Policies\Constraint\DimensionRegistry;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\AccountStatus;
use Summae\Core\Substrate\AccountType;
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

        return ['type' => $typeCode, 'code' => $code];
    }

    private function requireDimensions(): DimensionRegistry
    {
        return $this->dimensions ?? throw new DomainError(
            'E_DIMENSION_INVALID',
            'this tenant was built without a dimension registry',
            ['type' => null],
        );
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
        $this->audit->record($actor, 'account', $account->id, 'created');

        return $account;
    }

    /** @param array<string, mixed> $input */
    public function lockAccount(array $input): Account
    {
        $actor = $this->audit->actorOf($input);
        $number = is_string($input['number'] ?? null) ? $input['number'] : '';
        $account = $this->accounts->byNumber(AccountNumber::of($number));

        if ($account === null) {
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf('Account %s does not exist', $number), ['number' => $number]);
        }

        $before = $account->status()->value;
        $account->lock();
        $this->accounts->save($account);
        $this->audit->record($actor, 'account', $account->id, 'locked', [
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
            $this->audit->record($actor, 'account', $account->id, 'created');
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
        $status = ($input['status'] ?? null) === AccountStatus::Locked->value
            ? AccountStatus::Locked
            : AccountStatus::Active;

        return new Account($this->ids->next(), AccountNumber::of($number), $name, $type, $subtype, $status);
    }
}
