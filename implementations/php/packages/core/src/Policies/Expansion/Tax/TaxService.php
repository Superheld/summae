<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Tax;

use Brick\Math\BigDecimal;
use Summae\Core\DomainError;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\Money;

/**
 * Steuerexpansion (tax-modell.md): side-effect-free — reine Funktion.
 *
 * Determinismus (determinismus.md §2): USt-Berechnung pro Beleg je
 * Steuersatz — Netto-Summe je Schlüssel bilden, Steuer berechnen,
 * EINMAL half-up runden. Versionswahl nach Belegdatum.
 *
 * Kleinunternehmer (§ 19 UStG, SF-11): zum Belegdatum aktiv -> keine
 * Steuerpositionen, Brutto = Netto.
 */
final readonly class TaxService
{
    public function __construct(
        private Currency $baseCurrency,
        private TaxCodeRegistry $registry,
        private TaxProfile $profile,
        private JournalRepository $journal,
        // Pack-Parameter: 'perVoucher' (Steuer einmal je Schlüssel) | 'perLine' (je Position).
        private string $taxRoundingGranularity = 'perVoucher',
    ) {
    }

    public function profile(): TaxProfile
    {
        return $this->profile;
    }

    public function registry(): TaxCodeRegistry
    {
        return $this->registry;
    }

    /**
     * @param array<string, mixed> $input date, taxCode?, direction, netLines[]
     *
     * @return array<string, mixed> netLines (getaggt), taxLines, grossTotal
     */
    public function expand(array $input): array
    {
        // v0.4 (§ 27 UStG): Regelversion folgt dem Leistungsdatum, Fallback Belegdatum.
        $date = is_string($input['serviceDate'] ?? null)
            ? $this->parseDate($input['serviceDate'])
            : $this->parseDate($input['date'] ?? null);
        $direction = ($input['direction'] ?? null) === 'input' ? 'input' : 'output';
        $defaultCode = is_string($input['taxCode'] ?? null) ? $input['taxCode'] : null;

        $rawLines = is_array($input['netLines'] ?? null) ? array_values($input['netLines']) : [];
        if ($rawLines === []) {
            throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'expandTax ohne Netto-Positionen');
        }

        /** @var list<array{account: string, money: Money, code: string}> $netLines */
        $netLines = [];
        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                throw new DomainError('E_ENTRY_INVALID_AMOUNT', 'Netto-Position ist keine Struktur');
            }

            $code = is_string($rawLine['taxCode'] ?? null) ? $rawLine['taxCode'] : $defaultCode;
            if ($code === null) {
                throw new DomainError('E_TAXCODE_UNKNOWN', 'Position ohne Steuerschlüssel (kein Default gesetzt)');
            }

            $netLines[] = [
                'account' => is_string($rawLine['account'] ?? null) ? $rawLine['account'] : '',
                'money' => $this->parseMoney($rawLine['money'] ?? null),
                'code' => $code,
            ];
        }

        // Referenzprüfung vollständig vor Berechnung: unbekannter Schlüssel
        // schlägt vor fehlender Version fehl, unabhängig von der Zeilenreihenfolge.
        foreach ($netLines as $line) {
            $this->registry->get($line['code']);
        }

        /** @var array<string, TaxCodeVersion> $versions */
        $versions = [];
        /** @var array<string, Money> $bases */
        $bases = [];
        foreach ($netLines as $line) {
            $versions[$line['code']] ??= $this->registry->versionFor($line['code'], $date);
            $bases[$line['code']] = ($bases[$line['code']] ?? Money::zero($this->baseCurrency))->add($line['money']);
        }

        $netTotal = Money::zero($this->baseCurrency);
        foreach ($netLines as $line) {
            $netTotal = $netTotal->add($line['money']);
        }

        // Kleinunternehmer: keine Steuer, keine Tags.
        if ($this->profile->smallBusinessAt($date)) {
            return [
                'netLines' => array_map(static fn (array $line): array => [
                    'account' => $line['account'],
                    'side' => $direction === 'output' ? 'credit' : 'debit',
                    'money' => $line['money']->jsonSerialize(),
                    'taxTag' => null,
                ], $netLines),
                'taxLines' => [],
                'grossTotal' => $netTotal->jsonSerialize(),
            ];
        }

        $sideFor = $direction === 'output' ? 'credit' : 'debit';

        // perLine (Pack-Parameter): Steuer je Position runden, eine Steuerzeile je
        // Position. Nur Standard-Mechanismus (perLine nicht mit RC/IC kombiniert).
        if ($this->taxRoundingGranularity === 'perLine') {
            $taxLines = [];
            $grossTotal = $netTotal;
            $resultNetLines = [];
            foreach ($netLines as $line) {
                $version = $versions[$line['code']];
                $tag = $this->tag($line['code'], $version, $version->reportingKey, $line['money']);
                $tax = Money::fromCalculation(
                    BigDecimal::of($line['money']->amountAsString())
                        ->multipliedBy(BigDecimal::of($version->rate))
                        ->dividedBy(100, 10, \Brick\Math\RoundingMode::UNNECESSARY),
                    $this->baseCurrency,
                );
                $taxLines[] = [
                    'account' => $version->taxAccount,
                    'side' => $sideFor,
                    'money' => $tax->jsonSerialize(),
                    'taxTag' => $tag,
                ];
                $grossTotal = $grossTotal->add($tax);
                $resultNetLines[] = [
                    'account' => $line['account'],
                    'side' => $sideFor,
                    'money' => $line['money']->jsonSerialize(),
                    'taxTag' => $tag,
                ];
            }

            return [
                'netLines' => $resultNetLines,
                'taxLines' => $taxLines,
                'grossTotal' => $grossTotal->jsonSerialize(),
            ];
        }

        // Gruppen deterministisch nach Steuerkonto sortieren (Codepoints).
        $codes = array_map(strval(...), array_keys($bases));
        usort($codes, static fn (string $a, string $b): int =>
            strcmp($versions[$a]->taxAccount, $versions[$b]->taxAccount));

        $taxLines = [];
        $taxTotal = Money::zero($this->baseCurrency);
        $grossTotal = $netTotal;
        /** @var array<string, array<string, mixed>> $baseTags Tag je Code für die Netto-Positionen */
        $baseTags = [];

        foreach ($codes as $code) {
            $version = $versions[$code];
            $base = $bases[$code];

            // Pro Beleg je Steuersatz: einmal rechnen, einmal runden (half-up).
            $tax = Money::fromCalculation(
                BigDecimal::of($base->amountAsString())
                    ->multipliedBy(BigDecimal::of($version->rate))
                    ->dividedBy(100, 10, \Brick\Math\RoundingMode::UNNECESSARY),
                $this->baseCurrency,
            );

            $mainSide = $direction === 'output' ? 'credit' : 'debit';

            if ($version->mechanism === 'intra_community_supply') {
                // igL (§ 4 Nr. 1b): steuerfrei — keine Steuerzeile, aber
                // Kennzahl-Tag an der Basis (ZM-Grundlage, v0.4).
                $baseTags[$code] = $this->tag($code, $version, $version->reportingKey, $base);
                continue;
            }

            if ($version->mechanism === 'reverse_charge') {
                // § 13b: USt und VSt gleichzeitig, je eigene Kennzahl; Zahlbetrag = Netto.
                $taxLines[] = [
                    'account' => $version->taxAccount,
                    'side' => 'credit',
                    'money' => $tax->jsonSerialize(),
                    'taxTag' => $this->tag($code, $version, $version->reportingKey, $base),
                ];
                $taxLines[] = [
                    'account' => $version->inputTaxAccount ?? $version->taxAccount,
                    'side' => 'debit',
                    'money' => $tax->jsonSerialize(),
                    'taxTag' => $this->tag($code, $version, $version->inputReportingKey, $base),
                ];
                $baseTags[$code] = $this->tag($code, $version, $version->baseReportingKey ?? $version->reportingKey, $base);
            } else {
                $taxLines[] = [
                    'account' => $version->taxAccount,
                    'side' => $mainSide,
                    'money' => $tax->jsonSerialize(),
                    'taxTag' => $this->tag($code, $version, $version->reportingKey, $base),
                ];
                $baseTags[$code] = $this->tag($code, $version, $version->reportingKey, $base);
                $taxTotal = $taxTotal->add($tax);
                $grossTotal = $grossTotal->add($tax);
            }
        }

        return [
            'netLines' => array_map(static fn (array $line): array => [
                'account' => $line['account'],
                'side' => $direction === 'output' ? 'credit' : 'debit',
                'money' => $line['money']->jsonSerialize(),
                'taxTag' => $baseTags[$line['code']] ?? null,
            ], $netLines),
            'taxLines' => $taxLines,
            'grossTotal' => $grossTotal->jsonSerialize(),
        ];
    }

    /**
     * Profiländerung zum Stichtag — nie rückwirkend in festgeschriebene
     * Zeiträume (E_PROFILE_RETROACTIVE_CONFLICT).
     *
     * @param array<string, mixed> $input
     */
    public function setProfile(array $input): TaxProfile
    {
        $smallBusiness = $input['smallBusiness'] ?? null;
        if (!is_array($smallBusiness) || !is_string($smallBusiness['validFrom'] ?? null)) {
            throw new DomainError('E_PROFILE_RETROACTIVE_CONFLICT', 'setTaxProfile braucht smallBusiness.validFrom');
        }

        $validFrom = $this->parseDate($smallBusiness['validFrom']);

        foreach ($this->journal->all() as $entry) {
            if ($entry->isFinalized() && !$entry->entryDate->isBefore($validFrom)) {
                throw new DomainError('E_PROFILE_RETROACTIVE_CONFLICT', sprintf(
                    'Zeitraum ab %s enthält festgeschriebene Buchungen (z. B. Nr. %d)',
                    $validFrom->iso,
                    $entry->sequenceNumber,
                ), ['validFrom' => $validFrom->iso, 'sequenceNumber' => $entry->sequenceNumber]);
            }
        }

        $this->profile->setSmallBusiness($validFrom, (bool) ($smallBusiness['value'] ?? false));

        return $this->profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function tag(string $code, TaxCodeVersion $version, ?string $reportingKey, Money $base): array
    {
        return [
            'code' => $code,
            'appliedVersion' => $version->validFrom->iso,
            'reportingKey' => $reportingKey,
            'baseMoney' => $base->jsonSerialize(),
        ];
    }

    private function parseDate(mixed $date): CalendarDate
    {
        try {
            return CalendarDate::of(is_string($date) ? $date : '');
        } catch (InvalidValue) {
            throw new DomainError('E_TAXCODE_NO_VALID_VERSION', 'Belegdatum fehlt oder ungültig');
        }
    }

    private function parseMoney(mixed $raw): Money
    {
        $amount = is_array($raw) && is_string($raw['amount'] ?? null) ? $raw['amount'] : null;

        if ($amount === null) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', 'Netto-Position ohne Betrag');
        }

        try {
            return Money::of($amount, $this->baseCurrency);
        } catch (InvalidValue) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Ungültiger Betrag "%s"', $amount));
        }
    }
}
