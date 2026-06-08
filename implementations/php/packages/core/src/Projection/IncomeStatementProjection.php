<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Projection;

use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Ledger\Side;
use Rechnungswesen\Core\Mapping\MappingRegistry;
use Rechnungswesen\Core\Port\AccountRepository;
use Rechnungswesen\Core\Port\JournalRepository;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\Money;

/**
 * GuV als Projektion über ein Mapping (SF-09). Genau ein Geschäftsjahr;
 * v0.4: fromPeriod/throughPeriod grenzen ab (Monats-GuV als
 * BWA-Grundlage), Jahressicht bleibt Default.
 *
 * Vorzeichen: Haben − Soll (Erträge positiv, Aufwand negativ);
 * netIncome = Summe der Positionen.
 */
final readonly class IncomeStatementProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private JournalRepository $journal,
        private MappingRegistry $mappings,
    ) {
    }

    /**
     * @param array<string, mixed> $params fiscalYear, fromPeriod?, throughPeriod?, mapping
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : 0;
        $fromPeriod = is_int($params['fromPeriod'] ?? null) ? $params['fromPeriod'] : 1;
        $throughPeriod = is_int($params['throughPeriod'] ?? null) ? $params['throughPeriod'] : PHP_INT_MAX;
        $mappingId = is_string($params['mapping'] ?? null) ? $params['mapping'] : '';

        $mapping = $this->mappings->byId($mappingId)
            ?? throw new DomainError('E_MAPPING_OVERLAP', sprintf('Mapping "%s" ist nicht geladen', $mappingId));

        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, Money> $amounts key -> Betrag */
        $amounts = [];
        /** @var array<string, true> $touched */
        $touched = [];

        foreach ($this->journal->forFiscalYear($fiscalYear) as $entry) {
            $period = $entry->periodRef->period;
            if ($period < $fromPeriod || $period > $throughPeriod) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                $account = $this->accounts->byId($line->accountId);
                if ($account === null || $account->type->isBalanceCarrying()) {
                    continue;
                }

                $leaf = $mapping->leafFor($account->number->value);
                if ($leaf === null) {
                    continue;
                }

                $signed = $line->side === Side::Credit ? $line->money : $line->money->negate();
                $amounts[$leaf['key']] = ($amounts[$leaf['key']] ?? $zero)->add($signed);
                $touched[$leaf['key']] = true;
            }
        }

        $positions = [];
        $netIncome = $zero;

        foreach ($mapping->leaves as $leaf) {
            $amount = $amounts[$leaf['key']] ?? $zero;
            $netIncome = $netIncome->add($amount);

            if ($amount->isZero() && !isset($touched[$leaf['key']])) {
                continue;
            }

            $positions[] = [
                'key' => $leaf['key'],
                'label' => $leaf['label'],
                'amount' => $amount->amountAsString(),
            ];
        }

        return [
            'positions' => $positions,
            'netIncome' => $netIncome->amountAsString(),
        ];
    }
}
