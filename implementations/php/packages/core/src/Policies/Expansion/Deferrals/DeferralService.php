<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Deferrals;

use Summae\Core\DomainError;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\DeferralRepository;
use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Records\Voucher;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Prepaid and deferred items: recognition and the release schedule (F-CORE-053).
 *
 * **The accounts were never the gap.** The shipped German chart has carried both from the start and
 * both have had a balance-sheet position. What was missing is the *plan*. An insurance premium paid
 * in December for the following year could be deferred and then had to be released by hand, month
 * after month, from memory — which is exactly the failure `runDepreciation` exists to prevent for
 * arithmetic that is identical: an amount spread evenly over a known number of periods. Two
 * mechanisms that differ only in whether the machine remembers is not a design, it is an omission.
 *
 * **Two kinds, opposites rather than variants.** A *prepaid expense* is money already paid for a
 * service still to come, so it is an asset and its release is an expense. A *deferred income* is
 * money already received for a service still to be rendered, so it is a liability and its release
 * is revenue. Every posting here flips with the kind; nothing else does.
 *
 * **The release run mirrors the depreciation run, deliberately and down to the answer shape.** One
 * period at a time, idempotent because each deferral records which periods it has released,
 * `alreadyRun` where there was nothing left to do. Somebody who has closed a period with
 * `runDepreciation` should not have to learn a second vocabulary for the same act.
 *
 * **Socket and plug.** Which account holds each kind is the pack's; which expense or revenue the
 * amount belongs to is the caller's, because it is a fact about the transaction rather than about
 * the jurisdiction.
 */
final class DeferralService
{
    /** @param array<string, mixed> $ruleModule the resolved bundle; `deferrals` is read here */
    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AccountRepository $accounts,
        private readonly FiscalYearRepository $fiscalYears,
        private readonly VoucherRepository $vouchers,
        private readonly DeferralRepository $deferrals,
        private readonly Ledger $ledger,
        private readonly IdGenerator $ids,
        private array $ruleModule = [],
        private readonly ?Uuid $tenantId = null,
        private readonly ?AuditWriter $audit = null,
    ) {
    }

    /** @param array<string, mixed> $ruleModule */
    public function setRuleModule(array $ruleModule): void
    {
        $this->ruleModule = $ruleModule;
    }

    /**
     * Defer an amount and fix its release plan (`recognizeDeferral`).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function recognize(array $input): array
    {
        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        if (!in_array($kind, Deferral::kinds(), true)) {
            throw new DomainError('E_INPUT_INVALID', sprintf(
                'recognizeDeferral: "%s" is not a deferral kind',
                $kind,
            ), ['field' => 'kind', 'known' => Deferral::kinds()]);
        }

        $account = AccountNumber::of($this->packAccount($kind));
        $counterAccount = AccountNumber::of($this->requireExistingAccount($input['counterAccount'] ?? null, 'counterAccount'));
        $reason = $this->requireString($input['reason'] ?? null, 'reason');
        $recognizedOn = $this->requireDate($input['recognizedOn'] ?? null, 'recognizedOn');
        $amount = $this->requireMoney($input['amount'] ?? null, 'amount');

        if (!$amount->isPositive()) {
            throw new DomainError('E_INPUT_INVALID', 'recognizeDeferral: amount must be positive', ['field' => 'amount']);
        }

        $periods = is_int($input['periods'] ?? null) ? $input['periods'] : 0;
        if ($periods < 1) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'recognizeDeferral: "periods" must be at least 1 — a deferral with no release plan is the '
                . 'hand-kept schedule this operation exists to replace',
                ['field' => 'periods'],
            );
        }

        $firstYear = is_int($input['firstFiscalYear'] ?? null) ? $input['firstFiscalYear'] : 0;
        $firstPeriod = is_int($input['firstPeriod'] ?? null) ? $input['firstPeriod'] : 0;
        if ($firstYear < 1 || $firstPeriod < 1) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'recognizeDeferral: firstFiscalYear and firstPeriod say when the release starts and are required',
                ['field' => 'firstFiscalYear'],
            );
        }

        // Largest-remainder, like every other distribution in this library: the instalments sum to
        // the amount exactly, and the drift lands in the earliest periods rather than in the last
        // one, where it would look like a correction.
        $shares = $amount->allocate(...array_fill(0, $periods, 1));
        $plan = [];
        foreach ($shares as $index => $share) {
            $plan[] = $this->periodAt($firstYear, $firstPeriod, $index) + ['amount' => $share];
        }

        // Recognition: a prepaid expense moves value OUT of the expense account and onto the asset;
        // a deferred income moves it out of revenue and onto the liability. The release, later,
        // runs each of these backwards.
        $lines = $kind === Deferral::PREPAID_EXPENSE
            ? [
                ['account' => $account->value, 'side' => 'debit', 'money' => $amount->jsonSerialize()],
                ['account' => $counterAccount->value, 'side' => 'credit', 'money' => $amount->jsonSerialize()],
            ]
            : [
                ['account' => $counterAccount->value, 'side' => 'debit', 'money' => $amount->jsonSerialize()],
                ['account' => $account->value, 'side' => 'credit', 'money' => $amount->jsonSerialize()],
            ];

        $entryId = $this->post($recognizedOn, sprintf('Deferral %s', $reason), 'RAP', $lines);

        $deferral = new Deferral(
            $this->ids->next(),
            $kind,
            $reason,
            $account,
            $counterAccount,
            $recognizedOn,
            $amount,
            $plan,
            $entryId,
        );
        $this->deferrals->add($deferral);

        $this->audit?->record($this->audit->actorOf($input), 'deferral', $deferral->id, 'recognized', [
            'kind' => ['from' => null, 'to' => $kind],
            'amount' => ['from' => null, 'to' => $amount->amountAsString()],
            'periods' => ['from' => null, 'to' => $periods],
        ]);

        return [
            'deferralId' => $deferral->id->value,
            'kind' => $kind,
            'amount' => $amount->jsonSerialize(),
            'periods' => $periods,
            'entryId' => $entryId->value,
        ];
    }

    /**
     * Release what a period owes, for every deferral (`runDeferralRelease`).
     *
     * The depreciation run's shape, on purpose: one period, idempotent, `alreadyRun` when there is
     * nothing left. A period that was already released books nothing a second time, because each
     * deferral records what it has released rather than deriving it from a balance.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function runRelease(array $input): array
    {
        $fiscalYear = is_int($input['fiscalYear'] ?? null) ? $input['fiscalYear'] : 0;
        $period = is_int($input['period'] ?? null) ? $input['period'] : 0;

        if ($fiscalYear < 1 || $period < 1) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'runDeferralRelease: fiscalYear and period are required',
                ['field' => 'fiscalYear'],
            );
        }

        $date = $this->periodEnd($fiscalYear, $period);
        $entriesCreated = 0;
        $total = Money::zero($this->baseCurrency);

        foreach ($this->deferrals->all() as $deferral) {
            if ($deferral->isReleased($fiscalYear, $period)) {
                continue;
            }

            $instalment = $deferral->instalmentFor($fiscalYear, $period);
            if ($instalment === null || $instalment->isZero()) {
                continue;
            }

            $lines = $deferral->kind === Deferral::PREPAID_EXPENSE
                ? [
                    ['account' => $deferral->counterAccount->value, 'side' => 'debit', 'money' => $instalment->jsonSerialize()],
                    ['account' => $deferral->account->value, 'side' => 'credit', 'money' => $instalment->jsonSerialize()],
                ]
                : [
                    ['account' => $deferral->account->value, 'side' => 'debit', 'money' => $instalment->jsonSerialize()],
                    ['account' => $deferral->counterAccount->value, 'side' => 'credit', 'money' => $instalment->jsonSerialize()],
                ];

            $entryId = $this->post(
                $date,
                sprintf('Deferral release %s %d/%02d', $deferral->reason, $fiscalYear, $period),
                'RAP-A',
                $lines,
            );

            $deferral->recordRelease($fiscalYear, $period, $instalment, $date, $entryId);
            $this->deferrals->save($deferral);
            $entriesCreated++;
            $total = $total->add($instalment);
        }

        // A run that created nothing is still an event, exactly as it is for depreciation: "somebody
        // ran the release for this period and it was already done" is what an auditor
        // reconstructing a timeline wants to see.
        if ($this->tenantId !== null) {
            $this->audit?->record($this->audit->actorOf($input), 'deferralRelease', $this->tenantId, 'completed', [
                'fiscalYear' => ['from' => null, 'to' => $fiscalYear],
                'period' => ['from' => null, 'to' => $period],
                'entriesCreated' => ['from' => null, 'to' => $entriesCreated],
            ]);
        }

        if ($entriesCreated === 0) {
            return ['alreadyRun' => true, 'entriesCreated' => 0];
        }

        return ['entriesCreated' => $entriesCreated, 'totalReleased' => $total->jsonSerialize()];
    }

    /**
     * What is deferred, over what, and how far it has run (`deferralRegister`).
     *
     * @param array<string, mixed> $params
     *
     * @return array{deferrals: list<array<string, mixed>>, outstandingTotal: string}
     */
    public function register(array $params): array
    {
        $kind = is_string($params['kind'] ?? null) ? $params['kind'] : null;
        $status = is_string($params['status'] ?? null) ? $params['status'] : null;

        $rows = [];
        $outstanding = Money::zero($this->baseCurrency);

        foreach ($this->deferrals->all() as $deferral) {
            $deferralStatus = $deferral->isSettled() ? 'settled' : 'open';
            if ($kind !== null && $deferral->kind !== $kind) {
                continue;
            }
            if ($status !== null && $deferralStatus !== $status) {
                continue;
            }

            $rows[] = [
                'deferralId' => $deferral->id->value,
                'kind' => $deferral->kind,
                'reason' => $deferral->reason,
                'account' => $deferral->account->value,
                'counterAccount' => $deferral->counterAccount->value,
                'recognizedOn' => $deferral->recognizedOn->iso,
                'amount' => $deferral->amount->amountAsString(),
                'released' => $deferral->releasedTotal()->amountAsString(),
                'outstanding' => $deferral->outstanding()->amountAsString(),
                'status' => $deferralStatus,
                'plan' => array_map(static fn (array $entry): array => [
                    'fiscalYear' => $entry['fiscalYear'],
                    'period' => $entry['period'],
                    'amount' => $entry['amount']->amountAsString(),
                    // The plan is the answer to "when will this be gone" and the releases are the
                    // answer to "what has actually happened". Reporting them as one flag per
                    // instalment keeps both in one place without a second list to line up.
                    'released' => $deferral->isReleased($entry['fiscalYear'], $entry['period']),
                ], $deferral->plan),
            ];
            $outstanding = $outstanding->add($deferral->outstanding());
        }

        return ['deferrals' => $rows, 'outstandingTotal' => $outstanding->amountAsString()];
    }

    /** @return array{fiscalYear: int, period: int} */
    private function periodAt(int $firstYear, int $firstPeriod, int $offset): array
    {
        $periodsPerYear = $this->periodsPerYear($firstYear);
        $zeroBased = $firstPeriod - 1 + $offset;

        return [
            'fiscalYear' => $firstYear + intdiv($zeroBased, $periodsPerYear),
            'period' => ($zeroBased % $periodsPerYear) + 1,
        ];
    }

    /**
     * How many periods a year has — read from the fiscal year where there is one, twelve otherwise.
     *
     * A release plan that ran off the end of a year has to know where the next one starts, and
     * assuming twelve for a tenant whose year is divided differently would put instalments in
     * periods that do not exist.
     */
    private function periodsPerYear(int $fiscalYear): int
    {
        $year = $this->fiscalYears->byYear($fiscalYear);
        $count = $year === null ? 0 : count($year->periods());

        return $count > 0 ? $count : 12;
    }

    private function periodEnd(int $fiscalYear, int $period): CalendarDate
    {
        $year = $this->fiscalYears->byYear($fiscalYear);
        if ($year !== null) {
            foreach ($year->periods() as $candidate) {
                if ($candidate->number === $period) {
                    return $candidate->end;
                }
            }
        }

        throw new DomainError('E_PERIOD_UNKNOWN', sprintf(
            'period %d of fiscal year %d does not exist',
            $period,
            $fiscalYear,
        ), ['fiscalYear' => $fiscalYear, 'period' => $period]);
    }

    private function packAccount(string $kind): string
    {
        $module = is_array($this->ruleModule['deferrals'] ?? null) ? $this->ruleModule['deferrals'] : null;
        if ($module === null) {
            throw new DomainError(
                'E_PACK_INCOHERENT',
                'a deferral was recognised, but the pack declares no prepaid or deferred accounts',
                ['field' => 'deferrals'],
            );
        }

        foreach (is_array($module['kinds'] ?? null) ? $module['kinds'] : [] as $declared) {
            if (is_array($declared) && ($declared['kind'] ?? null) === $kind && is_string($declared['account'] ?? null)) {
                return $declared['account'];
            }
        }

        throw new DomainError('E_PACK_INCOHERENT', sprintf(
            'the pack declares no account for deferral kind "%s"',
            $kind,
        ), ['kind' => $kind]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function post(CalendarDate $date, string $text, string $voucherPrefix, array $lines): Uuid
    {
        $voucher = new Voucher(
            $this->ids->next(),
            sprintf('%s-%s', $voucherPrefix, str_replace('-', '', $date->iso)),
            $date,
            kind: 'internal',
        );
        $this->vouchers->add($voucher);

        $result = $this->ledger->post([
            'entryDate' => $date->iso,
            'voucherId' => $voucher->id->value,
            'text' => $text,
            'lines' => $lines,
        ]);
        $this->ledger->finalize(['entryId' => $result->entry->id->value]);

        return $result->entry->id;
    }

    private function requireString(mixed $raw, string $field): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s is required', $field), ['field' => $field]);
        }

        return $raw;
    }

    private function requireExistingAccount(mixed $raw, string $field): string
    {
        $number = $this->requireString($raw, $field);

        if ($this->accounts->byNumber(AccountNumber::of($number)) === null) {
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf('account %s does not exist', $number), ['account' => $number]);
        }

        return $number;
    }

    private function requireDate(mixed $raw, string $field): CalendarDate
    {
        if (!is_string($raw) || $raw === '') {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s is required', $field), ['field' => $field]);
        }

        try {
            return CalendarDate::of($raw);
        } catch (InvalidValue) {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s is not a calendar date', $field), ['field' => $field]);
        }
    }

    private function requireMoney(mixed $raw, string $field): Money
    {
        if (!is_array($raw) || !is_string($raw['amount'] ?? null)) {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s must be a money object', $field), ['field' => $field]);
        }

        $currency = is_string($raw['currency'] ?? null) ? $raw['currency'] : $this->baseCurrency->code;

        try {
            return Money::of($raw['amount'], $currency);
        } catch (InvalidValue) {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s is not a valid amount', $field), ['field' => $field]);
        }
    }
}
