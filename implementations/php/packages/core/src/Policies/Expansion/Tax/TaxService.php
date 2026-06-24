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
 * Tax expansion (tax-modell.md): side-effect-free — pure function.
 *
 * Determinism (determinismus.md §2): VAT computed per voucher per tax
 * rate — sum net per code, compute tax, round half-up ONCE. Version
 * selection by voucher date.
 *
 * Small-business exemption (SF-11): active at voucher date -> no tax
 * lines, gross = net.
 */
final readonly class TaxService
{
    public function __construct(
        private Currency $baseCurrency,
        private TaxCodeRegistry $registry,
        private TaxProfile $profile,
        private JournalRepository $journal,
        // Pack parameter: 'perVoucher' (tax once per code) | 'perLine' (per line).
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
     * @return array<string, mixed> netLines (tagged), taxLines, grossTotal
     */
    public function expand(array $input): array
    {
        // v0.4: rule version follows the service date, fallback voucher date.
        $date = is_string($input['serviceDate'] ?? null)
            ? $this->parseDate($input['serviceDate'])
            : $this->parseDate($input['date'] ?? null);
        $direction = ($input['direction'] ?? null) === 'input' ? 'input' : 'output';
        $defaultCode = is_string($input['taxCode'] ?? null) ? $input['taxCode'] : null;

        $rawLines = is_array($input['netLines'] ?? null) ? array_values($input['netLines']) : [];
        if ($rawLines === []) {
            throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'expandTax without net lines');
        }

        /** @var list<array{account: string, money: Money, code: string}> $netLines */
        $netLines = [];
        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                throw new DomainError('E_ENTRY_INVALID_AMOUNT', 'net line is not a structure');
            }

            $code = is_string($rawLine['taxCode'] ?? null) ? $rawLine['taxCode'] : $defaultCode;
            if ($code === null) {
                throw new DomainError('E_TAXCODE_UNKNOWN', 'line without tax code (no default set)');
            }

            $netLines[] = [
                'account' => is_string($rawLine['account'] ?? null) ? $rawLine['account'] : '',
                'money' => $this->parseMoney($rawLine['money'] ?? null),
                'code' => $code,
            ];
        }

        // Reference check fully before computation: an unknown code fails
        // before a missing version, independent of line order.
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

        // Small business: no tax, no tags.
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

        // perLine (pack parameter): round tax per line, one tax line per
        // line. Standard mechanism only (perLine not combined with RC/IC).
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

        // Sort groups deterministically by tax account (codepoints).
        $codes = array_map(strval(...), array_keys($bases));
        usort($codes, static fn (string $a, string $b): int =>
            strcmp($versions[$a]->taxAccount, $versions[$b]->taxAccount));

        $taxLines = [];
        $grossTotal = $netTotal;
        /** @var array<string, array<string, mixed>> $baseTags tag per code for the net lines */
        $baseTags = [];

        foreach ($codes as $code) {
            $version = $versions[$code];
            $base = $bases[$code];

            // Per voucher per tax rate: compute once, round once (half-up).
            $tax = Money::fromCalculation(
                BigDecimal::of($base->amountAsString())
                    ->multipliedBy(BigDecimal::of($version->rate))
                    ->dividedBy(100, 10, \Brick\Math\RoundingMode::UNNECESSARY),
                $this->baseCurrency,
            );

            $mainSide = $direction === 'output' ? 'credit' : 'debit';
            $tag = fn (?string $reportingKey): array => $this->tag($code, $version, $reportingKey, $base);
            $contribution = TaxMechanisms::mechanismFor($version->mechanism)->contribute(
                $version,
                $tax,
                $mainSide,
                $tag,
                Money::zero($this->baseCurrency),
            );
            foreach ($contribution['taxLines'] as $line) {
                $taxLines[] = $line;
            }
            $baseTags[$code] = $contribution['baseTag'];
            $grossTotal = $grossTotal->add($contribution['grossDelta']);
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
     * Profile change at cutoff date — never retroactive into finalized
     * periods (E_PROFILE_RETROACTIVE_CONFLICT).
     *
     * @param array<string, mixed> $input
     */
    public function setProfile(array $input): TaxProfile
    {
        $smallBusiness = $input['smallBusiness'] ?? null;
        if (!is_array($smallBusiness) || !is_string($smallBusiness['validFrom'] ?? null)) {
            throw new DomainError('E_PROFILE_RETROACTIVE_CONFLICT', 'setTaxProfile requires smallBusiness.validFrom');
        }

        $validFrom = $this->parseDate($smallBusiness['validFrom']);

        foreach ($this->journal->all() as $entry) {
            if ($entry->isFinalized() && !$entry->entryDate->isBefore($validFrom)) {
                throw new DomainError('E_PROFILE_RETROACTIVE_CONFLICT', sprintf(
                    'period from %s contains finalized entries (e.g. no. %d)',
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
            throw new DomainError('E_TAXCODE_NO_VALID_VERSION', 'voucher date missing or invalid');
        }
    }

    private function parseMoney(mixed $raw): Money
    {
        $amount = is_array($raw) && is_string($raw['amount'] ?? null) ? $raw['amount'] : null;

        if ($amount === null) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', 'net line without amount');
        }

        try {
            return Money::of($amount, $this->baseCurrency);
        } catch (InvalidValue) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('invalid amount "%s"', $amount));
        }
    }
}
