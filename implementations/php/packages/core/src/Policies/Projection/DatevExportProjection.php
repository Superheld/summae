<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Substrate\EntryLine;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Partner\Partner;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;

/**
 * DATEV export (F-IO-005, v0.4 both directions): posting batch,
 * account labels, business-partner master data.
 *
 * Batch lines: tax lines arise on the DATEV side from the BU key
 * (alias column of the tax-code module — own codes stay
 * authoritative) and are therefore folded into the base line; composite
 * postings are resolved into sub-lines (position order).
 * Exact EXTF header format: to be verified against current DATEV docs.
 */
final readonly class DatevExportProjection
{
    public function __construct(
        private JournalRepository $journal,
        private AccountRepository $accounts,
        private VoucherRepository $vouchers,
        private PartnerRepository $partners,
        private TaxCodeRegistry $registry,
    ) {
    }

    /**
     * @param array<string, mixed> $params kind?, fiscalYear?, fromPeriod?, throughPeriod?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $kind = is_string($params['kind'] ?? null) ? $params['kind'] : 'entries';

        $rows = match ($kind) {
            'accounts' => $this->accountRows(),
            'partners' => $this->partnerRows(),
            default => $this->entryRows($params),
        };

        return [
            'kind' => $kind,
            'rows' => $rows,
            'rowCount' => count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function entryRows(array $params): array
    {
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : null;
        $fromPeriod = is_int($params['fromPeriod'] ?? null) ? $params['fromPeriod'] : 1;
        $throughPeriod = is_int($params['throughPeriod'] ?? null) ? $params['throughPeriod'] : PHP_INT_MAX;

        $entries = $fiscalYear === null ? $this->journal->all() : $this->journal->forFiscalYear($fiscalYear);
        $rows = [];

        foreach ($entries as $entry) {
            $period = $entry->periodRef->period;
            if ($period < $fromPeriod || $period > $throughPeriod) {
                continue;
            }

            foreach ($this->splitEntry($entry) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Splits a posting into DATEV batch lines: tax lines are added
     * to the tagged base line (BU generates them on the DATEV side);
     * the first untagged line is the (money) account of the row.
     *
     * @return list<array<string, mixed>>
     */
    private function splitEntry(JournalEntry $entry): array
    {
        $lead = null;
        /** @var list<EntryLine> $contraLines */
        $contraLines = [];
        /** @var list<EntryLine> $taxLines */
        $taxLines = [];

        foreach ($entry->lines() as $line) {
            $account = $this->accounts->byId($line->accountId);
            $isTaxLine = in_array($account?->subtype, ['tax_in', 'tax_out'], true) && $line->taxTag !== null;

            if ($isTaxLine) {
                $taxLines[] = $line;
                continue;
            }

            if ($lead === null && $line->taxTag === null) {
                $lead = $line;
                continue;
            }

            $contraLines[] = $line;
        }

        if ($lead === null || $contraLines === []) {
            return [];
        }

        $voucher = $this->vouchers->byId($entry->voucherId);
        $rows = [];

        foreach ($contraLines as $contra) {
            // Gross of the sub-line: base + associated tax (same tag code).
            $gross = $contra->money;
            $buKey = null;

            $contraCode = $contra->taxTag['code'] ?? null;
            if (is_string($contraCode)) {
                $buKey = $this->registry->datevBuFor($contraCode);

                foreach ($taxLines as $taxLine) {
                    if (($taxLine->taxTag['code'] ?? null) === $contraCode) {
                        $gross = $gross->add($taxLine->money);
                    }
                }
            }

            $rows[] = [
                'amount' => $gross->abs()->amountAsString(),
                'debitCredit' => $lead->side->value === 'debit' ? 'S' : 'H',
                'account' => $lead->account->value,
                'contraAccount' => $contra->account->value,
                'buKey' => $buKey,
                'documentField1' => $voucher === null ? '' : $voucher->voucherNumber,
                'date' => sprintf('%02d%02d', $entry->entryDate->month(), (int) substr($entry->entryDate->iso, 8, 2)),
                'text' => $entry->text(),
                'finalized' => $entry->isFinalized(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountRows(): array
    {
        $rows = [];

        foreach ($this->accounts->all() as $account) {
            $rows[] = [
                'number' => $account->number->value,
                'name' => $account->name,
                'type' => $account->type->value,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function partnerRows(): array
    {
        return array_map(
            static fn (Partner $partner): array => $partner->jsonSerialize(),
            $this->partners->all(),
        );
    }
}
