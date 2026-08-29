<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Policies\Expansion\Assets\Asset;
use Summae\Core\Port\AssetRepository;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;

/**
 * The fixed-asset movement schedule (F-CORE-055).
 *
 * **The register answers a different question, and that is the whole reason this exists.**
 * `assetRegister` reports the *stock*: what an asset cost, what has been written off it, what it is
 * worth, at a cutoff date. A movement schedule reports the *year*: what was there at the start, what
 * came in, what went out, what was written off during it, and what is left. Every figure below is
 * already in the journal and in the asset records — the projection that shapes them was simply
 * never written, which is why the row sat in the census as "a projection over data that is all
 * present".
 *
 * **Grouped by asset account, because that is what the statement wants.** A schedule is read
 * alongside the balance sheet, position by position, not asset by asset. Both are reported: the
 * per-asset rows because that is where a figure is checked, and the per-account totals because that
 * is where it is filed.
 *
 * **`transfers` is always `0.00`, and saying why is more useful than leaving the column out.**
 * A statutory schedule has a column for reclassifications between positions. summae has no operation
 * that moves an asset from one account to another, so the column is *structurally* zero rather than
 * unimplemented — a schedule missing the column would be incomplete for whoever files it, and one
 * that silently omitted it would be worse. If a transfer operation ever arrives, this column starts
 * carrying figures and nothing else about the shape changes.
 *
 * **What a disposal does to the year.** The whole accumulated depreciation of a disposed asset
 * leaves with it: it is reported under `onDisposals` and the closing accumulated depreciation is
 * zero. Netting it into the year's depreciation instead would show a year that wrote off less than
 * it did.
 */
final readonly class AssetScheduleProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private AssetRepository $assets,
    ) {
    }

    /**
     * @param array<string, mixed> $params fiscalYear (required)
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $fiscalYear = Parameters::integerOr($params['fiscalYear'] ?? null, 0);
        $yearStart = CalendarDate::of(sprintf('%04d-01-01', $fiscalYear));
        $yearEnd = CalendarDate::of(sprintf('%04d-12-31', $fiscalYear));

        $assets = $this->assets->all();
        usort($assets, static function (Asset $a, Asset $b): int {
            $byAccount = strcmp($a->assetAccount->value, $b->assetAccount->value);
            if ($byAccount !== 0) {
                return $byAccount;
            }
            $byDate = $a->acquiredOn->compareTo($b->acquiredOn);

            return $byDate !== 0 ? $byDate : $a->id->compareTo($b->id);
        });

        $rows = [];
        /** @var array<string, array<string, Money>> $byAccount */
        $byAccount = [];

        foreach ($assets as $asset) {
            // An asset acquired after the year has no line in it at all. One disposed before it has
            // none either — both would otherwise show a row of zeros that a reader has to discount.
            if ($asset->acquiredOn->isAfter($yearEnd)) {
                continue;
            }
            if ($asset->disposedOn() !== null && $asset->disposedOn()->isBefore($yearStart)) {
                continue;
            }

            $row = $this->scheduleFor($asset, $fiscalYear, $yearStart, $yearEnd);
            $rows[] = ['assetId' => $asset->id->value, 'name' => $asset->name, 'account' => $asset->assetAccount->value] + $row;

            $account = $asset->assetAccount->value;
            $byAccount[$account] ??= $this->zeroFigures();
            foreach ($this->zeroFigures() as $key => $_) {
                $byAccount[$account][$key] = $byAccount[$account][$key]->add(Money::of($row[$key], $this->baseCurrency));
            }
        }

        $accounts = array_keys($byAccount);
        sort($accounts, SORT_STRING);

        $groups = [];
        $totals = $this->zeroFigures();
        foreach ($accounts as $account) {
            $group = ['account' => $account];
            foreach ($byAccount[$account] as $key => $value) {
                $group[$key] = $value->amountAsString();
                $totals[$key] = $totals[$key]->add($value);
            }
            $groups[] = $group;
        }

        $totalsOut = [];
        foreach ($totals as $key => $value) {
            $totalsOut[$key] = $value->amountAsString();
        }

        return [
            'fiscalYear' => $fiscalYear,
            'assets' => $rows,
            'byAccount' => $groups,
            'totals' => $totalsOut,
        ];
    }

    /**
     * @return array<string, Money>
     */
    private function zeroFigures(): array
    {
        $zero = Money::zero($this->baseCurrency);

        return [
            'openingCost' => $zero,
            'additions' => $zero,
            'disposals' => $zero,
            'transfers' => $zero,
            'closingCost' => $zero,
            'openingDepreciation' => $zero,
            'depreciationOfYear' => $zero,
            'writeUpsOfYear' => $zero,
            'depreciationOnDisposals' => $zero,
            'closingDepreciation' => $zero,
            'openingBookValue' => $zero,
            'closingBookValue' => $zero,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function scheduleFor(Asset $asset, int $fiscalYear, CalendarDate $yearStart, CalendarDate $yearEnd): array
    {
        $zero = Money::zero($this->baseCurrency);
        $acquiredInYear = !$asset->acquiredOn->isBefore($yearStart);
        $disposedOn = $asset->disposedOn();
        $disposedInYear = $disposedOn !== null && !$disposedOn->isAfter($yearEnd);

        $openingCost = $acquiredInYear ? $zero : $asset->acquisitionCost;
        $additions = $acquiredInYear ? $asset->acquisitionCost : $zero;
        $disposals = $disposedInYear ? $asset->acquisitionCost : $zero;
        $closingCost = $openingCost->add($additions)->subtract($disposals);

        $openingDepreciation = $acquiredInYear
            ? $zero
            : $asset->accumulatedDepreciationAt($this->dayBefore($yearStart));

        $depreciationOfYear = $zero;
        $writeUpsOfYear = $zero;
        foreach ($asset->depreciationsForPersistence() as $booking) {
            $date = CalendarDate::of(is_string($booking['date'] ?? null) ? $booking['date'] : '1970-01-01');
            if ($date->isBefore($yearStart) || $date->isAfter($yearEnd)) {
                continue;
            }

            /** @var array<string, mixed> $amountData */
            $amountData = is_array($booking['amount'] ?? null) ? $booking['amount'] : [];
            $amount = Money::of(is_string($amountData['amount'] ?? null) ? $amountData['amount'] : '0', $this->baseCurrency);

            if (($booking['kind'] ?? null) === 'writeUp') {
                // A write-up is stored as a negative depreciation so every existing reader picks it
                // up; here it is reported positive under its own name, because a schedule that
                // showed it as "less depreciation" would hide a legally distinct event.
                $writeUpsOfYear = $writeUpsOfYear->add($amount->negate());
                continue;
            }

            $depreciationOfYear = $depreciationOfYear->add($amount);
        }

        $depreciationOnDisposals = $disposedInYear
            ? $openingDepreciation->add($depreciationOfYear)->subtract($writeUpsOfYear)
            : $zero;

        $closingDepreciation = $openingDepreciation
            ->add($depreciationOfYear)
            ->subtract($writeUpsOfYear)
            ->subtract($depreciationOnDisposals);

        return [
            'openingCost' => $openingCost->amountAsString(),
            'additions' => $additions->amountAsString(),
            'disposals' => $disposals->amountAsString(),
            // Structurally zero: see the class comment. The column is here so the schedule is
            // complete in shape, not because a transfer could occur and did not.
            'transfers' => $zero->amountAsString(),
            'closingCost' => $closingCost->amountAsString(),
            'openingDepreciation' => $openingDepreciation->amountAsString(),
            'depreciationOfYear' => $depreciationOfYear->amountAsString(),
            'writeUpsOfYear' => $writeUpsOfYear->amountAsString(),
            'depreciationOnDisposals' => $depreciationOnDisposals->amountAsString(),
            'closingDepreciation' => $closingDepreciation->amountAsString(),
            'openingBookValue' => $openingCost->subtract($openingDepreciation)->amountAsString(),
            'closingBookValue' => $closingCost->subtract($closingDepreciation)->amountAsString(),
        ];
    }

    /**
     * The last day before a fiscal year starts — `accumulatedDepreciationAt` is inclusive, and a
     * schedule's opening figure is what stood there *before* the year, not on its first day.
     */
    private function dayBefore(CalendarDate $date): CalendarDate
    {
        return CalendarDate::of(
            (new \DateTimeImmutable($date->iso))->modify('-1 day')->format('Y-m-d'),
        );
    }
}
