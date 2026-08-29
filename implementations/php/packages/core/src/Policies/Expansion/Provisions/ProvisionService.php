<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Provisions;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Summae\Core\DomainError;
use Summae\Core\Ledger\AuditWriter;
use Summae\Core\Ledger\Ledger;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\ProvisionRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Records\Voucher;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\AccountSubtype;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;

/**
 * Provisions: formation, use, release, re-measurement (F-CORE-051).
 *
 * **Why this is a duty and not a feature.** A provision is the one balance-sheet item a business
 * must recognise for something that has *not yet happened* — an obligation whose amount or timing
 * is uncertain. Leaving it out overstates the result and the equity, which is why the law makes it
 * mandatory rather than optional. summae had nothing: no account, no position, no operation, zero
 * occurrences in either core. A balance sheet without it is not merely incomplete, it is wrong in a
 * direction that flatters.
 *
 * **Four operations, because there are four events and they mean different things.** Recognising is
 * an expense for a future obligation. *Using* it is that obligation coming true. *Releasing* it is
 * the obligation going away — income the business never had to pay. *Re-measuring* is the estimate
 * moving while the obligation stands. A design with one "adjust" would net these into a number that
 * answers none of the questions an auditor asks, which is the same defect as reading a provision
 * off an account balance.
 *
 * **Socket and plug.** The core knows *that* a provision is recognised as an expense against a
 * liability account, that using it settles against something else, that releasing it is income, and
 * that a long-dated one is discounted. Which accounts, whether discounting applies at all and from
 * what remaining term — all pack.
 *
 * **The discount rate is deliberately not shipped, and that is a decision rather than a gap.** The
 * pack declares the *rule* (discount from a remaining term of n months, compounded annually) and
 * cites its basis; the *rate* arrives per act as an input. In Germany it is an average of the last
 * seven years' market rates, published **monthly** — a number that would be stale in a pack file
 * before anybody upgraded, and a stale legal rate that looks authoritative is worse than an absent
 * one. So a provision that must be discounted and carries no rate is refused, by name, rather than
 * recognised undiscounted.
 */
final class ProvisionService
{
    /** @param array<string, mixed> $ruleModule the resolved bundle; `provisions` is read here */
    public function __construct(
        private readonly Currency $baseCurrency,
        private readonly AccountRepository $accounts,
        private readonly VoucherRepository $vouchers,
        private readonly ProvisionRepository $provisions,
        private readonly Ledger $ledger,
        private readonly IdGenerator $ids,
        private array $ruleModule = [],
        private readonly ?AuditWriter $audit = null,
    ) {
    }

    /** @param array<string, mixed> $ruleModule */
    public function setRuleModule(array $ruleModule): void
    {
        $this->ruleModule = $ruleModule;
    }

    /**
     * Recognise a provision (`recognizeProvision`).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function recognize(array $input): array
    {
        $module = $this->packModule();
        $number = $this->requireString($input['account'] ?? null, 'account');
        $declared = $this->declaredAccount($module, $number);

        $reason = $this->requireString($input['reason'] ?? null, 'reason');
        $recognizedOn = $this->requireDate($input['recognizedOn'] ?? null, 'recognizedOn');
        $dueDate = $this->optionalDate($input['dueDate'] ?? null, 'dueDate');
        $settlementAmount = $this->requireMoney($input['amount'] ?? null, 'amount');

        if (!$settlementAmount->isPositive()) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'recognizeProvision: amount must be positive — a provision of nothing is not a provision',
                ['field' => 'amount'],
            );
        }

        [$recognized, $rate] = $this->discount($module, $settlementAmount, $recognizedOn, $dueDate, $input);

        $provision = new Provision(
            $this->ids->next(),
            $reason,
            AccountNumber::of($number),
            AccountNumber::of($declared['expenseAccount']),
            AccountNumber::of($declared['releaseAccount']),
            $recognizedOn,
            $dueDate,
            $settlementAmount,
            $recognized,
            $rate,
        );

        $entryId = $this->post($recognizedOn, sprintf('Provision %s', $reason), 'RST', [
            ['account' => $declared['expenseAccount'], 'side' => 'debit', 'money' => $recognized->jsonSerialize()],
            ['account' => $number, 'side' => 'credit', 'money' => $recognized->jsonSerialize()],
        ]);

        $provision->record('recognized', $recognizedOn, $recognized, $entryId);
        $this->provisions->add($provision);
        $this->trace($input, $provision, 'recognized', [
            'reason' => ['from' => null, 'to' => $reason],
            'amount' => ['from' => null, 'to' => $recognized->amountAsString()],
        ]);

        return [
            'provisionId' => $provision->id->value,
            'settlementAmount' => $settlementAmount->jsonSerialize(),
            'carryingAmount' => $recognized->jsonSerialize(),
            'discounted' => $rate !== null,
            'discountRate' => $rate,
            'entryId' => $entryId->value,
        ];
    }

    /**
     * The obligation came true (`useProvision`).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function use(array $input): array
    {
        $provision = $this->require($input['provisionId'] ?? null);
        $date = $this->requireDate($input['date'] ?? null, 'date');
        $amount = $this->requireMoney($input['amount'] ?? null, 'amount');
        $settlementAccount = $this->requireExistingAccount($input['settlementAccount'] ?? null, 'settlementAccount');

        if (!$amount->isPositive()) {
            throw new DomainError('E_INPUT_INVALID', 'useProvision: amount must be positive', ['field' => 'amount']);
        }

        $carrying = $provision->carryingAmount();
        // The overshoot is the case worth getting right, and it is common: the invoice arrives
        // larger than the estimate. What was provided for is taken out of the provision; the rest
        // is an expense of the year the invoice arrived, NOT a retroactive correction of the year
        // the provision was formed. Netting the two would move an expense across a closed year.
        $fromProvision = $amount->compareTo($carrying) > 0 ? $carrying : $amount;
        $excess = $amount->subtract($fromProvision);

        $lines = [];
        if (!$fromProvision->isZero()) {
            $lines[] = ['account' => $provision->account->value, 'side' => 'debit', 'money' => $fromProvision->jsonSerialize()];
        }
        if (!$excess->isZero()) {
            $lines[] = ['account' => $provision->expenseAccount->value, 'side' => 'debit', 'money' => $excess->jsonSerialize()];
        }
        $lines[] = ['account' => $settlementAccount, 'side' => 'credit', 'money' => $amount->jsonSerialize()];

        $entryId = $this->post($date, sprintf('Provision used: %s', $provision->reason), 'RST-V', $lines);

        $provision->moveCarryingAmount($fromProvision->negate());
        $provision->record('used', $date, $fromProvision, $entryId, $excess->isZero() ? null : sprintf(
            'settled at %s, %s more than provided for',
            $amount->amountAsString(),
            $excess->amountAsString(),
        ));
        $this->provisions->save($provision);
        $this->trace($input, $provision, 'used', [
            'carryingAmount' => ['from' => $carrying->amountAsString(), 'to' => $provision->carryingAmount()->amountAsString()],
        ]);

        return [
            'provisionId' => $provision->id->value,
            'usedFromProvision' => $fromProvision->jsonSerialize(),
            'excessExpense' => $excess->jsonSerialize(),
            'carryingAmount' => $provision->carryingAmount()->jsonSerialize(),
            'status' => $provision->status(),
            'entryId' => $entryId->value,
        ];
    }

    /**
     * The reason ceased (`releaseProvision`).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function release(array $input): array
    {
        $provision = $this->require($input['provisionId'] ?? null);
        $date = $this->requireDate($input['date'] ?? null, 'date');
        $carrying = $provision->carryingAmount();
        $amount = ($input['amount'] ?? null) === null
            ? $carrying
            : $this->requireMoney($input['amount'], 'amount');

        if ($amount->isNegative()) {
            throw new DomainError('E_INPUT_INVALID', 'releaseProvision: amount must not be negative', ['field' => 'amount']);
        }

        if ($amount->compareTo($carrying) > 0) {
            throw new DomainError('E_PROVISION_EXCEEDS_CARRYING', sprintf(
                'provision %s carries %s — %s cannot be released from it',
                $provision->id->value,
                $carrying->amountAsString(),
                $amount->amountAsString(),
            ), ['provisionId' => $provision->id->value, 'carryingAmount' => $carrying->amountAsString()]);
        }

        $entryId = null;
        if (!$amount->isZero()) {
            $entryId = $this->post($date, sprintf('Provision released: %s', $provision->reason), 'RST-A', [
                ['account' => $provision->account->value, 'side' => 'debit', 'money' => $amount->jsonSerialize()],
                ['account' => $provision->releaseAccount->value, 'side' => 'credit', 'money' => $amount->jsonSerialize()],
            ]);
            $provision->moveCarryingAmount($amount->negate());
        }

        $provision->record('released', $date, $amount, $entryId);
        $this->provisions->save($provision);
        $this->trace($input, $provision, 'released', [
            'carryingAmount' => ['from' => $carrying->amountAsString(), 'to' => $provision->carryingAmount()->amountAsString()],
        ]);

        return [
            'provisionId' => $provision->id->value,
            'released' => $amount->jsonSerialize(),
            'carryingAmount' => $provision->carryingAmount()->jsonSerialize(),
            'status' => $provision->status(),
            'entryId' => $entryId?->value,
        ];
    }

    /**
     * The estimate moved while the obligation stands (`remeasureProvision`).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function remeasure(array $input): array
    {
        $provision = $this->require($input['provisionId'] ?? null);
        $date = $this->requireDate($input['date'] ?? null, 'date');
        $target = $this->requireMoney($input['amount'] ?? null, 'amount');

        if ($target->isNegative()) {
            throw new DomainError('E_INPUT_INVALID', 'remeasureProvision: amount must not be negative', ['field' => 'amount']);
        }

        [$targetCarrying, $rate] = $this->discount($this->packModule(), $target, $date, $provision->dueDate, $input);

        $carrying = $provision->carryingAmount();
        $delta = $targetCarrying->subtract($carrying);

        $entryId = null;
        if (!$delta->isZero()) {
            // An increase is a further expense; a decrease is income under the release account,
            // because that is what a partial reversal of a provision IS — the same account a full
            // release books to, so the two cannot be told apart in the accounts by accident.
            $lines = $delta->isPositive()
                ? [
                    ['account' => $provision->expenseAccount->value, 'side' => 'debit', 'money' => $delta->jsonSerialize()],
                    ['account' => $provision->account->value, 'side' => 'credit', 'money' => $delta->jsonSerialize()],
                ]
                : [
                    ['account' => $provision->account->value, 'side' => 'debit', 'money' => $delta->abs()->jsonSerialize()],
                    ['account' => $provision->releaseAccount->value, 'side' => 'credit', 'money' => $delta->abs()->jsonSerialize()],
                ];

            $entryId = $this->post($date, sprintf('Provision remeasured: %s', $provision->reason), 'RST-B', $lines);
            $provision->moveCarryingAmount($delta);
        }

        $provision->record('remeasured', $date, $delta, $entryId, $rate === null ? null : sprintf('discounted at %s%%', $rate));
        $this->provisions->save($provision);
        $this->trace($input, $provision, 'remeasured', [
            'carryingAmount' => ['from' => $carrying->amountAsString(), 'to' => $provision->carryingAmount()->amountAsString()],
        ]);

        return [
            'provisionId' => $provision->id->value,
            'change' => $delta->jsonSerialize(),
            'carryingAmount' => $provision->carryingAmount()->jsonSerialize(),
            'discounted' => $rate !== null,
            'discountRate' => $rate,
            'status' => $provision->status(),
            'entryId' => $entryId?->value,
        ];
    }

    /**
     * The register (`provisionRegister`).
     *
     * @param array<string, mixed> $params
     *
     * @return array{provisions: list<array<string, mixed>>, total: string}
     */
    public function register(array $params): array
    {
        $status = is_string($params['status'] ?? null) ? $params['status'] : null;
        $asOf = $this->optionalDate($params['asOf'] ?? null, 'asOf');

        $rows = [];
        $total = Money::zero($this->baseCurrency);

        foreach ($this->provisions->all() as $provision) {
            if ($status !== null && $provision->status() !== $status) {
                continue;
            }
            if ($asOf !== null && $provision->recognizedOn->iso > $asOf->iso) {
                continue;
            }

            $movements = [];
            foreach ($provision->movements() as $movement) {
                if ($asOf !== null && $movement['date']->iso > $asOf->iso) {
                    continue;
                }
                $movements[] = [
                    'kind' => $movement['kind'],
                    'date' => $movement['date']->iso,
                    'amount' => $movement['amount']->amountAsString(),
                    'entryId' => $movement['entryId']?->value,
                    'note' => $movement['note'],
                ];
            }

            $rows[] = [
                'provisionId' => $provision->id->value,
                'reason' => $provision->reason,
                'account' => $provision->account->value,
                'recognizedOn' => $provision->recognizedOn->iso,
                'dueDate' => $provision->dueDate?->iso,
                'settlementAmount' => $provision->settlementAmount->amountAsString(),
                'carryingAmount' => $provision->carryingAmount()->amountAsString(),
                'discountRate' => $provision->discountRate,
                'status' => $provision->status(),
                'movements' => $movements,
            ];
            $total = $total->add($provision->carryingAmount());
        }

        return ['provisions' => $rows, 'total' => $total->amountAsString()];
    }

    /**
     * Discounting: mechanism here, everything jurisdictional in the pack.
     *
     * @param array<string, mixed> $module
     * @param array<string, mixed> $input
     *
     * @return array{0: Money, 1: string|null}
     */
    private function discount(array $module, Money $amount, CalendarDate $from, ?CalendarDate $dueDate, array $input): array
    {
        $rule = is_array($module['discounting'] ?? null) ? $module['discounting'] : null;
        if ($rule === null || $dueDate === null) {
            return [$amount, null];
        }

        $fromMonths = is_int($rule['fromMonths'] ?? null) ? $rule['fromMonths'] : 12;
        $months = self::monthsBetween($from, $dueDate);

        if ($months <= $fromMonths) {
            return [$amount, null];
        }

        $rate = $input['discountRate'] ?? null;
        if (!is_string($rate) || $rate === '') {
            // Refused rather than recognised undiscounted, and the message says what is needed.
            // The rate is published periodically and is not a pack constant — a stale legal rate
            // that looks authoritative is worse than an absent one.
            throw new DomainError('E_PROVISION_DISCOUNT_RATE_REQUIRED', sprintf(
                'this provision runs %d months and must be discounted — supply discountRate (%s)',
                $months,
                is_string($rule['basis'] ?? null) ? $rule['basis'] : 'see the pack',
            ), ['months' => $months, 'fromMonths' => $fromMonths]);
        }

        try {
            $percent = BigDecimal::of($rate);
        } catch (MathException) {
            throw new DomainError('E_INPUT_INVALID', 'discountRate is not a decimal number', ['field' => 'discountRate']);
        }

        if ($percent->isNegative()) {
            throw new DomainError('E_INPUT_INVALID', 'discountRate must not be negative', ['field' => 'discountRate']);
        }

        // **The compounding convention, and why it is this one.** Whole years compound; the
        // remaining months of the stub period accrue simple interest:
        //
        //     PV = amount / ( (1 + r)^years x (1 + r x months/12) )
        //
        // The statute prescribes a rate, not a convention, so this is a choice — and it is made for
        // a reason a shared oracle forces: a genuine fractional power (1+r)^(n/12) is a
        // transcendental, and computing one in PHP and in Node would put the two a cent apart on
        // some inputs. Everything here is exact decimal arithmetic — an integer power, one
        // multiplication, one division — so both languages reach the same cent by construction.
        // The mixed convention is ordinary practice for a stub period and errs on the small side of
        // the discount, which is the prudent direction for a liability.
        $years = intdiv($months, 12);
        $stubMonths = $months % 12;

        $r = $percent->dividedBy(BigDecimal::of('100'), 12, RoundingMode::HalfUp);
        $factor = BigDecimal::one()->plus($r)->power(max(0, $years));
        $stub = BigDecimal::one()->plus(
            $r->multipliedBy($stubMonths)->dividedBy(BigDecimal::of('12'), 12, RoundingMode::HalfUp),
        );

        // Scale 20 on the division, because that is big.js's default division precision on the
        // other side. Rounding twice at the same two scales in both languages is what makes the
        // last cent equal; rounding at different intermediate scales would not.
        $value = Money::fromCalculation(
            BigDecimal::of($amount->amountAsString())->dividedBy($factor->multipliedBy($stub), 20, RoundingMode::HalfUp),
            $this->baseCurrency,
        );

        return [$value, (string) $percent];
    }

    private static function monthsBetween(CalendarDate $from, CalendarDate $to): int
    {
        $fromYear = (int) substr($from->iso, 0, 4);
        $fromMonth = (int) substr($from->iso, 5, 2);
        $fromDay = (int) substr($from->iso, 8, 2);
        $toYear = (int) substr($to->iso, 0, 4);
        $toMonth = (int) substr($to->iso, 5, 2);
        $toDay = (int) substr($to->iso, 8, 2);

        $months = ($toYear - $fromYear) * 12 + ($toMonth - $fromMonth);
        if ($toDay < $fromDay) {
            --$months;
        }

        return $months;
    }

    /**
     * @param array<string, mixed> $module
     *
     * @return array{expenseAccount: string, releaseAccount: string}
     */
    private function declaredAccount(array $module, string $number): array
    {
        foreach (is_array($module['accounts'] ?? null) ? $module['accounts'] : [] as $declared) {
            if (!is_array($declared) || ($declared['account'] ?? null) !== $number) {
                continue;
            }

            $expense = $declared['expenseAccount'] ?? null;
            $release = $declared['releaseAccount'] ?? null;
            if (!is_string($expense) || !is_string($release)) {
                throw new DomainError('E_PACK_INCOHERENT', sprintf(
                    'the pack declares provision account %s without an expense or release account',
                    $number,
                ), ['account' => $number]);
            }

            return ['expenseAccount' => $expense, 'releaseAccount' => $release];
        }

        // Two guards, and this is the one that blames the right party. The subtype check below says
        // "you named the wrong account"; this one says "your pack has nothing to say about this
        // account", which needs a different fix.
        $account = $this->accounts->byNumber(AccountNumber::of($number));
        if ($account === null) {
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf(
                'recognizeProvision: account %s does not exist',
                $number,
            ), ['account' => $number]);
        }

        if ($account->subtype !== AccountSubtype::Provision->value) {
            throw new DomainError('E_PROVISION_ACCOUNT_INVALID', sprintf(
                'recognizeProvision: account %s is not a provision account (subtype "provision")',
                $number,
            ), ['account' => $number, 'subtype' => $account->subtype]);
        }

        throw new DomainError('E_PACK_INCOHERENT', sprintf(
            'the pack declares no expense and release account for provision account %s',
            $number,
        ), ['account' => $number]);
    }

    /** @return array<string, mixed> */
    private function packModule(): array
    {
        /** @var array<string, mixed>|null $module */
        $module = is_array($this->ruleModule['provisions'] ?? null) ? $this->ruleModule['provisions'] : null;

        return $module ?? throw new DomainError(
            'E_PACK_INCOHERENT',
            'a provision was recognised, but the pack declares no provision accounts',
            ['field' => 'provisions'],
        );
    }

    public function require(mixed $provisionId): Provision
    {
        $provision = null;

        if (is_string($provisionId) && $provisionId !== '') {
            try {
                $provision = $this->provisions->byId(Uuid::fromString($provisionId));
            } catch (InvalidValue) {
                $provision = null;
            }
        }

        return $provision ?? throw new DomainError('E_PROVISION_UNKNOWN', sprintf(
            'provision %s does not exist',
            is_string($provisionId) ? $provisionId : '',
        ), ['provisionId' => $provisionId]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function trace(array $input, Provision $provision, string $action, array $changes): void
    {
        $this->audit?->record($this->audit->actorOf($input), 'provision', $provision->id, $action, $changes);
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
        // Machine-generated, like a depreciation run and a stock valuation: finalized immediately,
        // because a hand correction would leave the register and the books disagreeing.
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
        return $this->optionalDate($raw, $field) ?? throw new DomainError(
            'E_INPUT_INVALID',
            sprintf('%s is required', $field),
            ['field' => $field],
        );
    }

    private function optionalDate(mixed $raw, string $field): ?CalendarDate
    {
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw) || $raw === '') {
            throw new DomainError('E_INPUT_INVALID', sprintf('%s is not a calendar date', $field), ['field' => $field]);
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
