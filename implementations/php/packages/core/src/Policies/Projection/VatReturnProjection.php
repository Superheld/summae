<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Records\OpenItem;
use Summae\Core\Substrate\Side;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;

/**
 * VAT return reporting keys via taxTags (SF-09).
 *
 * - Accrual taxation: the posting date counts.
 * - Cash taxation: the VAT return follows the open-item settlements (settledAt);
 *   partial payment proportional (half-up), final payment gets the remainder —
 *   Σ shares = total tax, exact (determinismus.md v0.3).
 *   Tagged postings without their own open item (cash sale, deemed supplies,
 *   prepayments, differences) count directly by the posting date.
 * - Presentation: tax bases per reporting key rounded DOWN to full euros
 *   (reporting-key sum), tax to the cent (api.md v0.3).
 */
final readonly class VatReturnProjection
{
    public function __construct(
        private Currency $baseCurrency,
        private JournalRepository $journal,
        private OpenItemRepository $openItems,
        private VoucherRepository $vouchers,
        private AccountRepository $accounts,
        private TaxCodeRegistry $registry,
        private TaxProfile $profile,
    ) {
    }

    /**
     * @param array<string, mixed> $params year, quarter, asOf?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $year = is_int($params['year'] ?? null) ? $params['year'] : 0;
        $quarter = is_int($params['quarter'] ?? null) ? $params['quarter'] : 0;
        $asOf = is_string($params['asOf'] ?? null) ? CalendarDate::of($params['asOf']) : null;

        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, array{base: Money, tax: Money}> $keys */
        $keys = [];
        $directions = $this->registryDirections();

        $add = function (string $key, Money $base, Money $tax) use (&$keys, $zero): void {
            /** @var array<string, array{base: Money, tax: Money}> $keys */
            $keys[$key] ??= ['base' => $zero, 'tax' => $zero];
            $keys[$key]['base'] = $keys[$key]['base']->add($base);
            $keys[$key]['tax'] = $keys[$key]['tax']->add($tax);
        };

        if ($this->profile->isCashBasis()) {
            // Settlements: proportional per payment, final remainder exact.
            foreach ($this->openItems->all() as $item) {
                $origin = $this->journal->byId($item->originEntryId);
                if ($origin === null || ($asOf !== null && $origin->entryDate->isAfter($asOf))) {
                    continue;
                }

                $contributions = $this->entryContributions($origin, $directions);
                if ($contributions === []) {
                    continue;
                }

                foreach ($this->allocateToSettlements($item, $contributions) as $share) {
                    if ($asOf !== null && $share['settledAt']->isAfter($asOf)) {
                        continue;
                    }

                    if ($this->inQuarter($share['settledAt'], $year, $quarter)) {
                        $add($share['key'], $share['base'], $share['tax']);
                    }
                }
            }

            // Tagged postings without their own open item count directly.
            foreach ($this->journal->all() as $entry) {
                if (!$this->inQuarter($entry->entryDate, $year, $quarter)) {
                    continue;
                }

                if ($asOf !== null && $entry->entryDate->isAfter($asOf)) {
                    continue;
                }

                if ($this->openItems->byOriginEntry($entry->id) !== []) {
                    continue;
                }

                foreach ($this->entryContributions($entry, $directions) as $key => $contribution) {
                    $add((string) $key, $contribution['base'], $contribution['tax']);
                }
            }
        } else {
            foreach ($this->journal->all() as $entry) {
                // v0.4: accrual assignment follows the supply date (fallback voucher date).
                // F-011: exception reversal/§17 correction. A reversing
                // posting inherits the original's voucher (reverse() copies voucherId)
                // and thus its supply date — but belongs in the VAT-return period
                // in which the correction is posted (§ 17 Abs. 1 S. 7 UStG), not
                // retroactively in the original period. Hence: by its own posting date.
                if ($entry->reverses !== null) {
                    $taxDate = $entry->entryDate;
                } else {
                    $voucher = $this->vouchers->byId($entry->voucherId);
                    $taxDate = $voucher === null ? $entry->entryDate : $voucher->taxDate();
                }

                if (!$this->inQuarter($taxDate, $year, $quarter)) {
                    continue;
                }

                if ($asOf !== null && $entry->entryDate->isAfter($asOf)) {
                    continue;
                }

                foreach ($this->entryContributions($entry, $directions) as $key => $contribution) {
                    $add((string) $key, $contribution['base'], $contribution['tax']);
                }
            }
        }

        ksort($keys, SORT_STRING);

        $result = [];
        $payload = $zero;

        // Touched reporting keys appear even at 0.00 (neutralization
        // visible, § 17 cases); never-touched ones are absent.
        foreach ($keys as $key => $amounts) {
            // Official VAT-return convention: round base down to full euros (reporting-key sum).
            $flooredBase = Money::fromCalculation(
                BigDecimal::of($amounts['base']->amountAsString())->toScale(0, RoundingMode::DOWN),
                $this->baseCurrency,
            );

            $result[(string) $key] = [
                'base' => $flooredBase->amountAsString(),
                'tax' => $amounts['tax']->amountAsString(),
            ];

            $direction = $directions[(string) $key] ?? 'output';
            $payload = $direction === 'input'
                ? $payload->subtract($amounts['tax'])
                : $payload->add($amounts['tax']);
        }

        return [
            'keys' => $result,
            'payload' => $payload->jsonSerialize(),
        ];
    }

    /**
     * Reporting key -> direction from the rule module (tax account subtype).
     *
     * @return array<string, string> reportingKey -> 'output'|'input'
     */
    private function registryDirections(): array
    {
        $directions = [];

        foreach ($this->registry->allVersions() as $version) {
            if ($version->reportingKey !== null) {
                $directions[$version->reportingKey] = $this->accountDirection($version->taxAccount);
            }

            if ($version->inputReportingKey !== null) {
                $directions[$version->inputReportingKey] = 'input';
            }

            if ($version->baseReportingKey !== null) {
                // Base reporting key follows the supply direction of the main position.
                $directions[$version->baseReportingKey] = $version->mechanism === 'reverse_charge'
                    ? 'input'
                    : $this->accountDirection($version->taxAccount);
            }
        }

        return $directions;
    }

    private function accountDirection(string $accountNumber): string
    {
        if ($accountNumber === '') {
            return 'output'; // tax-exempt reporting keys (igL) without a tax account
        }

        $account = $this->accounts->byNumber(AccountNumber::of($accountNumber));

        return $account?->subtype === 'tax_in' ? 'input' : 'output';
    }

    /**
     * Contributions of a posting per reporting key. Tax lines provide the tax
     * (sign-correct by side) and the tax base from the
     * taxTag.baseMoney (signed — corrections carry a negative base,
     * e.g. cash discount/bad debt § 17). Only when NO tax line
     * provides a base (e.g. base reporting key under reverse charge),
     * the base comes from the tagged non-tax lines.
     *
     * @param array<string, string> $directions
     *
     * @return array<string, array{base: Money, tax: Money}>
     */
    private function entryContributions(JournalEntry $entry, array $directions): array
    {
        $zero = Money::zero($this->baseCurrency);
        /** @var array<string, array{baseFromTax: Money, hasTaxBase: bool, baseFallback: Money, tax: Money}> $collected */
        $collected = [];

        foreach ($entry->lines() as $line) {
            $tag = $line->taxTag;
            if ($tag === null) {
                continue;
            }

            $key = $tag['reportingKey'] ?? null;
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $key = (string) $key;
            $account = $this->accounts->byId($line->accountId);
            $subtype = $account?->subtype;
            $collected[$key] ??= [
                'baseFromTax' => $zero,
                'hasTaxBase' => false,
                'baseFallback' => $zero,
                'tax' => $zero,
            ];

            if ($subtype === 'tax_out' || $subtype === 'tax_in') {
                $positiveSide = $subtype === 'tax_out' ? Side::Credit : Side::Debit;
                $signed = $line->side === $positiveSide ? $line->money : $line->money->negate();
                $collected[$key]['tax'] = $collected[$key]['tax']->add($signed);

                $baseMoney = $this->tagBaseMoney($tag);
                if ($baseMoney !== null) {
                    // Reversal: a negated tax line also negates the base.
                    if ($line->money->isNegative()) {
                        $baseMoney = $baseMoney->negate();
                    }

                    $collected[$key]['baseFromTax'] = $collected[$key]['baseFromTax']->add($baseMoney);
                    $collected[$key]['hasTaxBase'] = true;
                }
            } else {
                $direction = $directions[$key] ?? 'output';
                $positiveSide = $direction === 'input' ? Side::Debit : Side::Credit;
                $signed = $line->side === $positiveSide ? $line->money : $line->money->negate();
                $collected[$key]['baseFallback'] = $collected[$key]['baseFallback']->add($signed);
            }
        }

        $contributions = [];
        foreach ($collected as $key => $parts) {
            $base = $parts['hasTaxBase'] ? $parts['baseFromTax'] : $parts['baseFallback'];

            if ($base->isZero() && $parts['tax']->isZero()) {
                continue;
            }

            $contributions[(string) $key] = ['base' => $base, 'tax' => $parts['tax']];
        }

        return $contributions;
    }

    /**
     * @param array<string, mixed> $tag
     */
    private function tagBaseMoney(array $tag): ?Money
    {
        $baseMoney = $tag['baseMoney'] ?? null;
        $amount = is_array($baseMoney) && is_string($baseMoney['amount'] ?? null) ? $baseMoney['amount'] : null;

        return $amount === null ? null : Money::of($amount, $this->baseCurrency);
    }

    /**
     * Distributes the reporting-key amounts of an open-item origin across its
     * settlements: proportional half-up, the final payment gets the remainder.
     *
     * @param array<string, array{base: Money, tax: Money}> $contributions
     *
     * @return list<array{key: string, base: Money, tax: Money, settledAt: CalendarDate}>
     */
    private function allocateToSettlements(OpenItem $item, array $contributions): array
    {
        $shares = [];
        /** @var array<string, array{base: Money, tax: Money}> $allocated */
        $allocated = [];
        $remaining = $item->money;
        $total = BigDecimal::of($item->money->amountAsString());

        foreach ($item->settlements() as $settlement) {
            $remaining = $remaining->subtract($settlement->money);
            $isFinal = $remaining->isZero();
            $ratio = BigDecimal::of($settlement->money->amountAsString());

            foreach ($contributions as $key => $contribution) {
                $allocated[$key] ??= [
                    'base' => Money::zero($this->baseCurrency),
                    'tax' => Money::zero($this->baseCurrency),
                ];

                if ($isFinal) {
                    $base = $contribution['base']->subtract($allocated[$key]['base']);
                    $tax = $contribution['tax']->subtract($allocated[$key]['tax']);
                } else {
                    $base = $this->proportional($contribution['base'], $ratio, $total);
                    $tax = $this->proportional($contribution['tax'], $ratio, $total);
                }

                $allocated[$key]['base'] = $allocated[$key]['base']->add($base);
                $allocated[$key]['tax'] = $allocated[$key]['tax']->add($tax);

                $shares[] = [
                    'key' => (string) $key,
                    'base' => $base,
                    'tax' => $tax,
                    'settledAt' => $settlement->settledAt,
                ];
            }
        }

        return $shares;
    }

    private function proportional(Money $total, BigDecimal $part, BigDecimal $whole): Money
    {
        if ($whole->isZero()) {
            return Money::zero($this->baseCurrency);
        }

        return Money::fromCalculation(
            BigDecimal::of($total->amountAsString())
                ->multipliedBy($part)
                ->dividedBy($whole, 10, RoundingMode::HALF_UP),
            $this->baseCurrency,
        );
    }

    private function inQuarter(CalendarDate $date, int $year, int $quarter): bool
    {
        if ($date->year() !== $year) {
            return false;
        }

        return $quarter === 0 || intdiv($date->month() - 1, 3) + 1 === $quarter;
    }
}
