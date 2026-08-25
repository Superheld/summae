<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Substrate\Side;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\Money;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxMechanisms;

/**
 * EC sales list basis (v0.4, SF-21): intra-community supplies per
 * VAT ID and period — from reporting-key tags of the intra-community-supply codes,
 * partner assignment via the voucher.
 *
 * **A supply that cannot be reported is reported as unreportable** (F-IO-011). The list is keyed by
 * VAT ID, so a supply whose partner has none used to fall out of it silently: two postings, one
 * with a VAT ID and one without, and the answer was one row and nothing else. That is the dangerous
 * direction — in the jurisdictions that have this report, a supply without the recipient's
 * registration number is typically not exempt at all, so what dropped out was exactly the case
 * where something is wrong. Same shape as `vatReturn.gapWarnings`,
 * and for the same reason: the warning belongs at the figures, next to what is filed, rather than
 * in a projection of its own that whoever files may never open.
 *
 * Not a refusal. Whether a missing VAT ID makes a supply taxable is jurisdiction law and the
 * embedding's call; the library's job is to make sure the case is never invisible.
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
     * @return array{rows: list<array<string, string>>, gapWarnings: list<array<string, mixed>>}
     */
    public function compute(array $params): array
    {
        $year = Parameters::integerOr($params['year'] ?? null, 0);
        $quarter = Parameters::integerOr($params['quarter'] ?? null, 0);

        $intraCommunityKeys = [];
        foreach ($this->registry->allVersions() as $version) {
            if (TaxMechanisms::mechanismFor($version->mechanism)->affectsEcSalesList() && $version->reportingKey !== null) {
                $intraCommunityKeys[$version->reportingKey] = true;
            }
        }

        /** @var array<string, Money> $byVatId */
        $byVatId = [];
        /** @var list<array<string, mixed>> $gapWarnings */
        $gapWarnings = [];

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

            foreach ($entry->lines() as $line) {
                $key = $line->taxTag['reportingKey'] ?? null;
                if (!is_string($key) && !is_int($key)) {
                    continue;
                }

                if (!isset($intraCommunityKeys[(string) $key])) {
                    continue;
                }

                // The line IS an intra-community supply — decided before the partner is looked at,
                // which is the whole change. Deciding it afterwards is what made a missing VAT ID
                // look like a posting that was never an intra-community supply to begin with.
                if ($vatId === null) {
                    $gapWarnings[] = [
                        'reason' => $partner === null
                            ? 'supply_without_partner'
                            : 'partner_without_vat_id',
                        'sequenceNumber' => $entry->sequenceNumber,
                        'entryDate' => $entry->entryDate->iso,
                        'reportingKey' => (string) $key,
                        'money' => $line->money->jsonSerialize(),
                        'partnerId' => $partner?->id->value,
                    ];

                    continue;
                }

                $signed = $line->side === Side::Credit ? $line->money : $line->money->negate();
                $byVatId[$vatId] = isset($byVatId[$vatId]) ? $byVatId[$vatId]->add($signed) : $signed;
            }
        }

        // Journal order: the order the postings happened in is the order somebody checking them
        // will work through. Same rule as vatReturn's warnings.
        usort($gapWarnings, static fn (array $a, array $b): int => $a['sequenceNumber'] <=> $b['sequenceNumber']);

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

        return ['rows' => $rows, 'gapWarnings' => $gapWarnings];
    }
}
