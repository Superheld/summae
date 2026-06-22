<?php

declare(strict_types=1);

namespace Summae\Core\Assets;

use Summae\Core\DomainError;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Ledger\Voucher;
use Summae\Core\Port\AssetRepository;
use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Anlagen-Nebenbuch (assets-modell.md): GWG-Weiche beim Zugang,
 * AfA-Lauf idempotent je Lauf-Ziel, Buchungen als normale
 * Journal-Buchungen über den Ledger (kein Sonderweg) — maschinell
 * erzeugte Buchungen werden sofort festgeschrieben (GoBD).
 *
 * AfA-Verteilung (determinismus.md §2): Monatswerte = allocate der AHK
 * über die Laufzeit (flach); Jahreswerte = allocate nach Monaten je
 * Kalenderjahr — kein Restwert-Rest, Σ = AHK exakt.
 *
 * Kontenauflösung (Spec-Lücke, siehe SPEC-FINDINGS): Regelmodul-Schlüssel
 * `depreciationExpenseAccount`/`gwgExpenseAccount`/`acquisitionCounterAccount`,
 * sonst Konvention: einziges bank-Konto als Gegenkonto; AfA-Konto per
 * Namenspräfix "AfA", GWG-Konto per Namensteil "GWG".
 */
final class AssetService
{
    /**
     * @param array<string, mixed> $ruleModule gwgThresholds, usefulLife, Konten-Schlüssel
     */
    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AssetRepository $assets,
        private readonly FiscalYearRepository $fiscalYears,
        private readonly VoucherRepository $vouchers,
        private readonly Ledger $ledger,
        private readonly IdGenerator $ids,
        private array $ruleModule = [],
    ) {
    }

    /** @param array<string, mixed> $ruleModule */
    public function setRuleModule(array $ruleModule): void
    {
        $this->ruleModule = $ruleModule;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function acquire(array $input): array
    {
        $name = is_string($input['name'] ?? null) ? $input['name'] : '';
        $assetClass = is_string($input['assetClass'] ?? null) ? $input['assetClass'] : '';
        $assetAccount = AccountNumber::of(is_string($input['assetAccount'] ?? null) ? $input['assetAccount'] : '0');
        $cost = $this->parseMoney($input['acquisitionCost'] ?? null);
        $acquiredOn = CalendarDate::of(is_string($input['acquiredOn'] ?? null) ? $input['acquiredOn'] : '');
        $voucherId = is_string($input['voucherId'] ?? null) ? Uuid::fromString($input['voucherId']) : throw new InvalidValue('acquireAsset braucht voucherId');
        $choice = is_string($input['gwgChoice'] ?? null) ? $input['gwgChoice'] : 'auto';

        $route = $this->resolveRoute($choice, $cost, $acquiredOn);

        $usefulLifeMonths = null;
        $schedule = [];
        if ($route === AssetRoute::Capitalize) {
            $usefulLifeMonths = $this->usefulLifeMonths($assetClass);
            $schedule = $cost->allocateEvenly($usefulLifeMonths);
        } elseif ($route === AssetRoute::Pool) {
            // Sammelposten § 6 Abs. 2a: starr 5 Jahre je 1/5, unabhängig von Abgängen.
            $usefulLifeMonths = 60;
            $annual = $cost->allocateEvenly(5);
            foreach ($annual as $yearAmount) {
                $monthly = $yearAmount->allocateEvenly(12);
                foreach ($monthly as $monthAmount) {
                    $schedule[] = $monthAmount;
                }
            }
        }

        $asset = new Asset(
            $this->ids->next(),
            $name,
            $assetClass,
            $assetAccount,
            $cost,
            $acquiredOn,
            $route,
            $usefulLifeMonths,
            $schedule,
            $voucherId,
        );

        $this->assets->add($asset);

        // Zugangsbuchung: Aktivierung bzw. Sofortaufwand gegen Geldkonto.
        $targetAccount = $route === AssetRoute::ImmediateExpense
            ? $this->gwgExpenseAccount()
            : $assetAccount->value;

        $this->postMachineEntry(
            $acquiredOn,
            $voucherId,
            sprintf('Anlagenzugang %s', $name),
            [
                ['account' => $targetAccount, 'side' => 'debit', 'money' => $cost->jsonSerialize()],
                ['account' => $this->counterAccount(), 'side' => 'credit', 'money' => $cost->jsonSerialize()],
            ],
        );

        $result = $asset->jsonSerialize();
        $result['route'] = $route->value;
        if ($route === AssetRoute::ImmediateExpense) {
            $result['expenseAccount'] = $targetAccount;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function dispose(array $input): array
    {
        $asset = $this->requireAsset($input['assetId'] ?? null);
        $asset->assertActive();

        $disposedOn = CalendarDate::of(is_string($input['disposedOn'] ?? null) ? $input['disposedOn'] : '');
        $asset->dispose($disposedOn);
        $this->assets->save($asset);

        $proceeds = is_array($input['proceeds'] ?? null) ? $this->parseMoney($input['proceeds']) : null;
        $proceedsAccount = is_string($input['proceedsAccount'] ?? null) ? $input['proceedsAccount'] : null;
        $bankAccount = is_string($input['bankAccount'] ?? null) ? $input['bankAccount'] : $this->counterAccount();

        if ($proceeds !== null && $proceedsAccount !== null) {
            $voucherId = is_string($input['voucherId'] ?? null)
                ? Uuid::fromString($input['voucherId'])
                : $asset->voucherId;

            $this->postMachineEntry(
                $disposedOn,
                $voucherId,
                sprintf('Anlagenabgang %s', $asset->name),
                [
                    ['account' => $bankAccount, 'side' => 'debit', 'money' => $proceeds->jsonSerialize()],
                    ['account' => $proceedsAccount, 'side' => 'credit', 'money' => $proceeds->jsonSerialize()],
                ],
            );
        }

        return $asset->jsonSerialize();
    }

    /**
     * AfA-Lauf: Jahres- oder Monatslauf, idempotent je Lauf-Ziel
     * (Wiederholung: No-op mit alreadyRun, api.md).
     *
     * @param array<string, mixed> $input {fiscalYear} | {fiscalYear, period}
     *
     * @return array<string, mixed>
     */
    public function runDepreciation(array $input): array
    {
        $fiscalYear = is_int($input['fiscalYear'] ?? null) ? $input['fiscalYear'] : 0;
        $period = is_int($input['period'] ?? null) ? $input['period'] : null;

        $entriesCreated = 0;
        $total = Money::zero($this->baseCurrency);

        foreach ($this->assets->all() as $asset) {
            if ($asset->route !== AssetRoute::Capitalize && $asset->route !== AssetRoute::Pool) {
                continue;
            }

            if ($asset->isDisposed()) {
                continue;
            }

            [$months, $amount] = $period === null
                ? $this->yearTarget($asset, $fiscalYear)
                : $this->monthTarget($asset, $fiscalYear, $period);

            if ($months === [] || $amount->isZero()) {
                continue;
            }

            $bookingDate = $this->bookingDate($asset, $fiscalYear, $period, $months);

            $entry = $this->postMachineEntry(
                $bookingDate,
                $this->depreciationVoucher($asset, $fiscalYear, $period),
                sprintf('AfA %s %d%s', $asset->name, $fiscalYear, $period === null ? '' : sprintf('/%02d', $period)),
                [
                    ['account' => $this->depreciationExpenseAccount(), 'side' => 'debit', 'money' => $amount->jsonSerialize()],
                    ['account' => $asset->assetAccount->value, 'side' => 'credit', 'money' => $amount->jsonSerialize()],
                ],
            );

            // Verteilung auf die Planmonate festhalten (Idempotenz + asOf).
            $monthAmounts = count($months) === 1
                ? [$amount]
                : $this->monthAmounts($asset, $months, $amount);

            foreach ($months as $index => $planMonth) {
                $asset->recordDepreciation($planMonth, $bookingDate, $monthAmounts[$index], $entry);
            }

            $this->assets->save($asset);
            $entriesCreated++;
            $total = $total->add($amount);
        }

        if ($entriesCreated === 0) {
            return ['alreadyRun' => true, 'entriesCreated' => 0];
        }

        return [
            'entriesCreated' => $entriesCreated,
            'totalDepreciation' => $total->jsonSerialize(),
        ];
    }

    public function requireAsset(mixed $assetId): Asset
    {
        $asset = null;

        if (is_string($assetId) && $assetId !== '') {
            try {
                $asset = $this->assets->byId(Uuid::fromString($assetId));
            } catch (InvalidValue) {
                $asset = null;
            }
        }

        return $asset ?? throw new DomainError('E_ASSET_UNKNOWN', sprintf(
            'Anlagegut %s existiert nicht',
            is_string($assetId) ? $assetId : '?',
        ));
    }

    // ---- intern ----------------------------------------------------------

    /**
     * Jahresziel: alle offenen Planmonate des Kalenderjahres; Betrag =
     * Jahres-Allokation (Monatsgewichte je Jahr) minus bereits Gebuchtes.
     *
     * @return array{0: list<int>, 1: Money}
     */
    private function yearTarget(Asset $asset, int $fiscalYear): array
    {
        $monthsByYear = [];
        $life = count($asset->monthlySchedule);

        for ($planMonth = 1; $planMonth <= $life; $planMonth++) {
            $year = $asset->planMonthDate($planMonth)->year();
            $monthsByYear[$year][] = $planMonth;
        }

        if (!isset($monthsByYear[$fiscalYear])) {
            return [[], Money::zero($this->baseCurrency)];
        }

        $years = array_keys($monthsByYear);
        $weights = array_map(static fn (int $year): int => count($monthsByYear[$year]), $years);
        $yearAmounts = $asset->acquisitionCost->allocate(...$weights);
        $yearIndex = array_search($fiscalYear, $years, true);
        if ($yearIndex === false) {
            return [[], Money::zero($this->baseCurrency)];
        }
        $yearAmount = $yearAmounts[$yearIndex];

        $openMonths = [];
        $bookedAmount = Money::zero($this->baseCurrency);

        foreach ($monthsByYear[$fiscalYear] as $planMonth) {
            if ($asset->isMonthBooked($planMonth)) {
                $bookedAmount = $bookedAmount->add($asset->monthlySchedule[$planMonth - 1]);
                continue;
            }

            $openMonths[] = $planMonth;
        }

        $amount = $yearAmount->subtract($bookedAmount);

        if ($openMonths === [] || !$amount->isPositive()) {
            return [[], Money::zero($this->baseCurrency)];
        }

        return [$openMonths, $amount];
    }

    /**
     * Monatsziel: der Planmonat, der in (fiscalYear, period) fällt —
     * Betrag aus dem flachen Monatsplan (determinismus.md §2).
     *
     * @return array{0: list<int>, 1: Money}
     */
    private function monthTarget(Asset $asset, int $fiscalYear, int $period): array
    {
        $year = $this->fiscalYears->byYear($fiscalYear);
        if ($year === null) {
            throw new DomainError('E_PERIOD_UNKNOWN', sprintf('Geschäftsjahr %d ist nicht angelegt', $fiscalYear));
        }

        $periodEntity = $year->period($period);
        $life = count($asset->monthlySchedule);

        for ($planMonth = 1; $planMonth <= $life; $planMonth++) {
            $date = $asset->planMonthDate($planMonth);

            if (!$periodEntity->contains($date)) {
                continue;
            }

            if ($asset->isMonthBooked($planMonth)) {
                return [[], Money::zero($this->baseCurrency)];
            }

            return [[$planMonth], $asset->monthlySchedule[$planMonth - 1]];
        }

        return [[], Money::zero($this->baseCurrency)];
    }

    /**
     * @param list<int> $months
     *
     * @return list<Money>
     */
    private function monthAmounts(Asset $asset, array $months, Money $total): array
    {
        // Jahresbetrag auf die offenen Monate verteilen — die Differenz zum
        // flachen Plan landet per largest remainder deterministisch vorn.
        $planned = array_map(
            static fn (int $planMonth): Money => $asset->monthlySchedule[$planMonth - 1],
            $months,
        );

        $plannedSum = Money::zero($this->baseCurrency);
        foreach ($planned as $amount) {
            $plannedSum = $plannedSum->add($amount);
        }

        if ($plannedSum->equals($total)) {
            return $planned;
        }

        return $total->allocateEvenly(count($months));
    }

    /** @param list<int> $months */
    private function bookingDate(Asset $asset, int $fiscalYear, ?int $period, array $months): CalendarDate
    {
        if ($period !== null) {
            $year = $this->fiscalYears->byYear($fiscalYear);

            if ($year !== null) {
                return $year->period($period)->end;
            }
        }

        $year = $this->fiscalYears->byYear($fiscalYear);

        return $year->end ?? $asset->planMonthDate($months[count($months) - 1]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function postMachineEntry(CalendarDate $date, Uuid $voucherId, string $text, array $lines): Uuid
    {
        $result = $this->ledger->post([
            'entryDate' => $date->iso,
            'voucherId' => $voucherId->value,
            'text' => $text,
            'lines' => $lines,
        ]);

        // Maschinell erzeugte Buchung: sofort festschreiben (GoBD).
        $this->ledger->finalize(['entryId' => $result->entry->id->value]);

        return $result->entry->id;
    }

    private function depreciationVoucher(Asset $asset, int $fiscalYear, ?int $period): Uuid
    {
        $voucher = new Voucher(
            $this->ids->next(),
            sprintf('AFA-%d%s-%s', $fiscalYear, $period === null ? '' : sprintf('-%02d', $period), substr($asset->id->value, -6)),
            CalendarDate::of(sprintf('%04d-12-31', $fiscalYear)),
            kind: 'internal',
        );
        $this->vouchers->add($voucher);

        return $voucher->id;
    }

    private function resolveRoute(string $choice, Money $cost, CalendarDate $acquiredOn): AssetRoute
    {
        if ($choice !== 'auto') {
            return AssetRoute::tryFrom($choice) ?? AssetRoute::Capitalize;
        }

        foreach ($this->thresholds() as $threshold) {
            $validFrom = CalendarDate::of($threshold['validFrom']);
            $validTo = $threshold['validTo'] === null ? null : CalendarDate::of($threshold['validTo']);

            if ($acquiredOn->isBefore($validFrom) || ($validTo !== null && $acquiredOn->isAfter($validTo))) {
                continue;
            }

            if ($cost->compareTo(Money::of($threshold['immediateMax'], $this->baseCurrency)) <= 0) {
                return AssetRoute::ImmediateExpense;
            }

            if (
                $threshold['poolMin'] !== null
                && $threshold['poolMax'] !== null
                && $cost->compareTo(Money::of($threshold['poolMin'], $this->baseCurrency)) >= 0
                && $cost->compareTo(Money::of($threshold['poolMax'], $this->baseCurrency)) <= 0
            ) {
                return AssetRoute::Pool;
            }
        }

        return AssetRoute::Capitalize;
    }

    /**
     * @return list<array{validFrom: string, validTo: ?string, immediateMax: string, poolMin: ?string, poolMax: ?string}>
     */
    private function thresholds(): array
    {
        $thresholds = [];

        foreach (is_array($this->ruleModule['gwgThresholds'] ?? null) ? $this->ruleModule['gwgThresholds'] : [] as $raw) {
            if (!is_array($raw) || !is_string($raw['validFrom'] ?? null) || !is_string($raw['immediateMax'] ?? null)) {
                continue;
            }

            $thresholds[] = [
                'validFrom' => $raw['validFrom'],
                'validTo' => is_string($raw['validTo'] ?? null) ? $raw['validTo'] : null,
                'immediateMax' => $raw['immediateMax'],
                'poolMin' => is_string($raw['poolMin'] ?? null) ? $raw['poolMin'] : null,
                'poolMax' => is_string($raw['poolMax'] ?? null) ? $raw['poolMax'] : null,
            ];
        }

        return $thresholds;
    }

    private function usefulLifeMonths(string $assetClass): int
    {
        foreach (is_array($this->ruleModule['usefulLife'] ?? null) ? $this->ruleModule['usefulLife'] : [] as $raw) {
            if (is_array($raw) && ($raw['assetClass'] ?? null) === $assetClass && is_int($raw['months'] ?? null)) {
                return $raw['months'];
            }
        }

        throw new DomainError('E_ASSET_UNKNOWN', sprintf(
            'Keine Nutzungsdauer für Anlagenklasse "%s" im Regelmodul (siehe SPEC-FINDINGS)',
            $assetClass,
        ));
    }

    private function counterAccount(): string
    {
        return $this->assetAccount('acquisitionCounterAccount');
    }

    private function depreciationExpenseAccount(): string
    {
        return $this->assetAccount('depreciationExpenseAccount');
    }

    private function gwgExpenseAccount(): string
    {
        return $this->assetAccount('gwgExpenseAccount');
    }

    /**
     * v0.5/F-004: Anlagenkonten kommen aus dem Regelmodul-Block
     * `assetAccounts` — keine Namens-Heuristik mehr.
     */
    private function assetAccount(string $key): string
    {
        $block = is_array($this->ruleModule['assetAccounts'] ?? null) ? $this->ruleModule['assetAccounts'] : [];
        $value = $block[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf(
            'assetAccounts.%s ist im Regelmodul nicht gesetzt',
            $key,
        ), ['key' => $key]);
    }

    private function parseMoney(mixed $raw): Money
    {
        $amount = is_array($raw) && is_string($raw['amount'] ?? null) ? $raw['amount'] : null;

        if ($amount === null) {
            throw new InvalidValue('Betrag fehlt');
        }

        return Money::of($amount, $this->baseCurrency);
    }
}
