<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Substrate\Side;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\Money;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;

/**
 * EC sales list basis (v0.4, SF-21): intra-community supplies per
 * VAT ID and period — from reporting-key tags of the intra-community-supply codes,
 * partner assignment via the voucher.
 */
final readonly class EcSalesListProjection
{
    public function __construct(
        private JournalRepository $journal,
        private VoucherRepository $vouchers,
        private PartnerRepository $partners,
        private TaxCodeRegistry $registry,
    ) {
    }

    /**
     * @param array<string, mixed> $params year, quarter
     *
     * @return array{rows: list<array<string, string>>}
     */
    public function compute(array $params): array
    {
        $year = is_int($params['year'] ?? null) ? $params['year'] : 0;
        $quarter = is_int($params['quarter'] ?? null) ? $params['quarter'] : 0;

        $intraCommunityKeys = [];
        foreach ($this->registry->allVersions() as $version) {
            if ($version->mechanism === 'intra_community_supply' && $version->reportingKey !== null) {
                $intraCommunityKeys[$version->reportingKey] = true;
            }
        }

        /** @var array<string, Money> $byVatId */
        $byVatId = [];

        foreach ($this->journal->all() as $entry) {
            $voucher = $this->vouchers->byId($entry->voucherId);
            $taxDate = $voucher === null ? $entry->entryDate : $voucher->taxDate();

            if ($taxDate->year() !== $year) {
                continue;
            }

            if ($quarter !== 0 && intdiv($taxDate->month() - 1, 3) + 1 !== $quarter) {
                continue;
            }

            $partner = $voucher?->partnerId === null ? null : $this->partners->byId($voucher->partnerId);
            $vatId = $partner?->vatId();
            if ($vatId === null) {
                continue;
            }

            foreach ($entry->lines() as $line) {
                $key = $line->taxTag['reportingKey'] ?? null;
                if (!is_string($key) && !is_int($key)) {
                    continue;
                }

                if (!isset($intraCommunityKeys[(string) $key])) {
                    continue;
                }

                $signed = $line->side === Side::Credit ? $line->money : $line->money->negate();
                $byVatId[$vatId] = isset($byVatId[$vatId]) ? $byVatId[$vatId]->add($signed) : $signed;
            }
        }

        $vatIds = array_map(strval(...), array_keys($byVatId));
        usort($vatIds, static fn (string $a, string $b): int => strcmp($a, $b));

        $rows = [];
        foreach ($vatIds as $vatId) {
            if ($byVatId[$vatId]->isZero()) {
                continue;
            }

            $rows[] = [
                'vatId' => $vatId,
                'amount' => $byVatId[$vatId]->amountAsString(),
                'kind' => 'supply',
            ];
        }

        return ['rows' => $rows];
    }
}
