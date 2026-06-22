<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection\Mapping;

use Summae\Core\DomainError;
use Summae\Core\Substrate\Account;
use Summae\Core\Port\AccountRepository;

/**
 * Mapping import (api.md): overlap (one account in multiple
 * positions) -> E_MAPPING_OVERLAP; gaps are not an error but
 * gapWarnings[] with the catch-all position `_unassigned`.
 *
 * Checked against the actually existing accounts of the tenant,
 * per mapping kind against the domain-relevant set of accounts.
 */
final readonly class MappingImporter
{
    public function __construct(
        private AccountRepository $accounts,
        private MappingRegistry $registry,
    ) {
    }

    /**
     * @param array<string, mixed> $input {mapping: {...}}
     *
     * @return array<string, mixed>
     */
    public function import(array $input): array
    {
        $data = is_array($input['mapping'] ?? null) ? $input['mapping'] : [];
        $mapping = Mapping::fromData($data);

        $gapWarnings = [];

        foreach ($this->relevantAccounts($mapping->kind) as $account) {
            $matches = [];

            foreach ($mapping->leaves as $leaf) {
                if ($this->leafMatches($leaf, $account->number->value)) {
                    $matches[] = $leaf['key'];
                }
            }

            if (count($matches) > 1) {
                throw new DomainError('E_MAPPING_OVERLAP', sprintf(
                    'Account %s falls into multiple positions: %s',
                    $account->number->value,
                    implode(', ', $matches),
                ), ['account' => $account->number->value, 'positions' => $matches]);
            }

            if ($matches === []) {
                $gapWarnings[] = ['account' => $account->number->value, 'assignedTo' => '_unassigned'];
            }
        }

        $this->registry->add($mapping);

        return [
            'imported' => true,
            'id' => $mapping->id,
            'kind' => $mapping->kind,
            'gapWarnings' => $gapWarnings,
        ];
    }

    /**
     * @return list<Account>
     */
    private function relevantAccounts(string $kind): array
    {
        return array_values(array_filter(
            $this->accounts->all(),
            static fn (Account $account): bool => match ($kind) {
                'balance-sheet' => $account->type->isBalanceCarrying(),
                'income-statement' => !$account->type->isBalanceCarrying(),
                default => false, // e.g. cash-basis-categories: deliberately partial
            },
        ));
    }

    /**
     * @param array{key: string, label: string, side: ?string, ranges: list<array{from: string, to: string}>, numbers: list<string>, includeNonCash: bool, includesNetIncome: bool, parents: list<string>} $leaf
     */
    private function leafMatches(array $leaf, string $accountNumber): bool
    {
        if (in_array($accountNumber, $leaf['numbers'], true)) {
            return true;
        }

        foreach ($leaf['ranges'] as $range) {
            if (strcmp($accountNumber, $range['from']) >= 0 && strcmp($accountNumber, $range['to']) <= 0) {
                return true;
            }
        }

        return false;
    }
}
