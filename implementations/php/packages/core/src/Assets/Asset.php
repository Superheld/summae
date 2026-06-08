<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Assets;

use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Shared\AccountNumber;
use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Money;
use Rechnungswesen\Core\Shared\Uuid;

/**
 * Anlagegut (assets-modell.md): Stammdaten + AfA-Plan + Lebenslauf.
 * Invarianten: Restbuchwert = AHK − Σ Abschreibungen, nie < 0;
 * keine AfA vor Zugang oder nach Abgang; jeder Lebenslauf-Schritt
 * referenziert seine Journal-Buchung.
 */
final class Asset implements \JsonSerializable
{
    /** @var list<array{planMonth: int, date: CalendarDate, amount: Money, entryId: Uuid}> */
    private array $depreciations = [];

    private bool $disposed = false;

    private ?CalendarDate $disposedOn = null;

    /**
     * @param list<Money> $monthlySchedule flacher allocate über die Laufzeit (determinismus.md §2)
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly string $name,
        public readonly string $assetClass,
        public readonly AccountNumber $assetAccount,
        public readonly Money $acquisitionCost,
        public readonly CalendarDate $acquiredOn,
        public readonly AssetRoute $route,
        public readonly ?int $usefulLifeMonths,
        public readonly array $monthlySchedule,
        public readonly Uuid $voucherId,
    ) {
    }

    /**
     * Rehydrierung aus Persistenz (Adapter).
     *
     * @param list<Money> $monthlySchedule
     * @param list<array{planMonth: int, date: CalendarDate, amount: Money, entryId: Uuid}> $depreciations
     */
    public static function restore(
        Uuid $id,
        string $name,
        string $assetClass,
        AccountNumber $assetAccount,
        Money $acquisitionCost,
        CalendarDate $acquiredOn,
        AssetRoute $route,
        ?int $usefulLifeMonths,
        array $monthlySchedule,
        Uuid $voucherId,
        array $depreciations,
        bool $disposed,
        ?CalendarDate $disposedOn,
    ): self {
        $asset = new self($id, $name, $assetClass, $assetAccount, $acquisitionCost, $acquiredOn, $route, $usefulLifeMonths, $monthlySchedule, $voucherId);
        $asset->depreciations = $depreciations;
        $asset->disposed = $disposed;
        $asset->disposedOn = $disposedOn;

        return $asset;
    }

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    public function assertActive(): void
    {
        if ($this->disposed) {
            throw new DomainError('E_ASSET_DISPOSED', sprintf(
                'Anlagegut %s ist bereits abgegangen (%s)',
                $this->id->value,
                $this->disposedOn->iso ?? '?',
            ), ['assetId' => $this->id->value]);
        }
    }

    public function dispose(CalendarDate $disposedOn): void
    {
        $this->assertActive();
        $this->disposed = true;
        $this->disposedOn = $disposedOn;
    }

    /** Kalenderjahr+Monat des Planmonats (1-basiert). */
    public function planMonthDate(int $planMonth): CalendarDate
    {
        $start = new \DateTimeImmutable($this->acquiredOn->iso);
        $month = $start->modify(sprintf('first day of +%d months', $planMonth - 1));

        return CalendarDate::of($month->modify('last day of this month')->format('Y-m-d'));
    }

    public function isMonthBooked(int $planMonth): bool
    {
        foreach ($this->depreciations as $booking) {
            if ($booking['planMonth'] === $planMonth) {
                return true;
            }
        }

        return false;
    }

    public function recordDepreciation(int $planMonth, CalendarDate $date, Money $amount, Uuid $entryId): void
    {
        $this->depreciations[] = [
            'planMonth' => $planMonth,
            'date' => $date,
            'amount' => $amount,
            'entryId' => $entryId,
        ];
    }

    /**
     * Lebenslauf in Persistenzform (Adapter).
     *
     * @return list<array<string, mixed>>
     */
    public function depreciationsForPersistence(): array
    {
        return array_map(static fn (array $booking): array => [
            'planMonth' => $booking['planMonth'],
            'date' => $booking['date']->iso,
            'amount' => $booking['amount']->jsonSerialize(),
            'entryId' => $booking['entryId']->value,
        ], $this->depreciations);
    }

    public function accumulatedDepreciationAt(?CalendarDate $asOf): Money
    {
        $sum = $this->acquisitionCost->subtract($this->acquisitionCost); // 0 in Mandantenwährung

        foreach ($this->depreciations as $booking) {
            if ($asOf !== null && $booking['date']->isAfter($asOf)) {
                continue;
            }

            $sum = $sum->add($booking['amount']);
        }

        return $sum;
    }

    public function bookValueAt(?CalendarDate $asOf): Money
    {
        if ($this->route !== AssetRoute::Capitalize) {
            return $this->acquisitionCost->subtract($this->acquisitionCost);
        }

        return $this->acquisitionCost->subtract($this->accumulatedDepreciationAt($asOf));
    }

    /**
     * AfA-Plan als prüfbare Zusammenfassung: aufeinanderfolgende Monate
     * gleicher Rate gruppiert ("months1to28" usw.) plus Summe.
     *
     * @return array<string, string>
     */
    public function scheduleSummary(): array
    {
        if ($this->monthlySchedule === []) {
            return [];
        }

        $summary = [];
        $total = $this->acquisitionCost->subtract($this->acquisitionCost);
        $runStart = 1;

        foreach ($this->monthlySchedule as $index => $amount) {
            $total = $total->add($amount);
            $isLast = $index === count($this->monthlySchedule) - 1;
            $next = $isLast ? null : $this->monthlySchedule[$index + 1];

            if ($next !== null && $next->equals($amount)) {
                continue;
            }

            $summary[sprintf('months%dto%d', $runStart, $index + 1)] = $amount->amountAsString();
            $runStart = $index + 2;
        }

        $summary['total'] = $total->amountAsString();

        return $summary;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'name' => $this->name,
            'assetClass' => $this->assetClass,
            'assetAccount' => $this->assetAccount->value,
            'route' => $this->route->value,
            'acquisitionCost' => $this->acquisitionCost->jsonSerialize(),
            'acquiredOn' => $this->acquiredOn->iso,
            'usefulLifeMonths' => $this->usefulLifeMonths,
            'status' => $this->disposed ? 'disposed' : 'active',
            'disposedOn' => $this->disposedOn?->iso,
            'voucherId' => $this->voucherId->value,
        ];
    }
}
