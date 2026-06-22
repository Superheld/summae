<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

use Summae\Core\DomainError;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DimensionValue;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\IdGenerator;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\AccountStatus;
use Summae\Core\Substrate\AccountType;
use Summae\Core\Substrate\EntryLine;
use Summae\Core\Substrate\EntryStatus;
use Summae\Core\Substrate\FiscalYear;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\OpenItemKind;
use Summae\Core\Substrate\Period;
use Summae\Core\Substrate\PostResult;
use Summae\Core\Substrate\SettlementDifferenceKind;
use Summae\Core\Substrate\Side;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Records\OpenItem;
use Summae\Core\Records\Voucher;
use Summae\Core\Policies\Constraint\DimensionRegistry;
use Summae\Core\Policies\Expansion\Settlement;

/**
 * Domain Service `post` and relatives (ledger-modell.md):
 * touches JournalEntry + FiscalYear + journal number — hence a Service.
 *
 * Check order when posting is part of the contract (api.md):
 * 1. Structure (E_ENTRY_TOO_FEW_LINES, E_ENTRY_INVALID_AMOUNT)
 * 2. References (E_ENTRY_NO_VOUCHER, E_ACCOUNT_UNKNOWN, E_ACCOUNT_LOCKED,
 *    E_DIMENSION_INVALID)
 * 3. Balance equation (E_ENTRY_UNBALANCED)
 * 4. Temporal context (E_PERIOD_UNKNOWN, E_PERIOD_CLOSED)
 * Only the first error is reported.
 */
final readonly class Ledger
{
    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private FiscalYearRepository $fiscalYears,
        private VoucherRepository $vouchers,
        private JournalRepository $journal,
        private OpenItemRepository $openItems,
        private AuditTrail $audit,
        private DimensionRegistry $dimensions,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function post(array $input): PostResult
    {
        $actor = $this->actor($input);

        // 1. Structure
        $rawLines = $input['lines'] ?? null;
        if (!is_array($rawLines) || count($rawLines) < 2) {
            throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'A posting needs at least two lines');
        }

        /** @var list<array{account: string, side: Side, money: Money, dimensions: list<DimensionValue>, taxTag: array<string, mixed>|null}> $parsed */
        $parsed = [];
        foreach (array_values($rawLines) as $index => $rawLine) {
            if (!is_array($rawLine)) {
                throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Line %d is not a structure', $index));
            }

            $parsed[] = $this->parseLine($rawLine, $index);
        }

        // 2. References
        $voucher = $this->requireVoucher($input['voucherId'] ?? null);
        $lines = $this->resolveLines($parsed);

        // 3. Balance equation
        $this->assertBalanced($lines);

        // 4. Temporal context
        $entryDate = $this->parseEntryDate($input['entryDate'] ?? null);
        [$fiscalYear, $period] = $this->openPeriodFor($entryDate);

        $text = is_string($input['text'] ?? null) ? $input['text'] : '';

        $entry = new JournalEntry(
            $this->ids->next(),
            $this->journal->nextSequenceNumber($fiscalYear->year),
            $entryDate,
            $voucher->voucherDate,
            $this->clock->now(),
            new PeriodRef($fiscalYear->year, $period->number),
            $voucher->id,
            $text,
            $lines,
        );

        $this->journal->append($entry);
        $this->recordAudit($actor, 'journalEntry', $entry->id, 'created');

        return new PostResult($entry, $this->createOpenItems($entry));
    }

    /**
     * AR/AP automation: debit on a receivable account -> receivable,
     * credit on a payable account -> payable (natural balance side).
     * Reversal postings create no new items.
     *
     * @return list<OpenItem>
     */
    private function createOpenItems(JournalEntry $entry): array
    {
        if ($entry->reverses !== null) {
            return [];
        }

        $created = [];
        $voucher = $this->vouchers->byId($entry->voucherId);

        foreach ($entry->lines() as $index => $line) {
            $account = $this->accounts->byId($line->accountId);
            $kind = match (true) {
                $account?->subtype === 'ar' && $line->side === Side::Debit => OpenItemKind::Receivable,
                $account?->subtype === 'ap' && $line->side === Side::Credit => OpenItemKind::Payable,
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            $item = new OpenItem(
                $this->ids->next(),
                $kind,
                $entry->id,
                $index,
                $line->money,
                $entry->voucherId,
                $entry->entryDate,
                $voucher?->partnerId,
            );

            $this->openItems->add($item);
            $created[] = $item;
        }

        return $created;
    }

    /**
     * Settlement: allocation payment -> open item(s), also partial;
     * always explicit, no FIFO automation (determinismus.md §3).
     * Differences (cash discount/write-off/small difference) per api.md G2 (v0.3).
     *
     * @param array<string, mixed> $input
     *
     * @return list<OpenItem> the affected items
     */
    public function settle(array $input): array
    {
        $actor = $this->actor($input);
        $entry = $this->requireEntry($input['entryId'] ?? null);

        $allocations = is_array($input['allocations'] ?? null) ? array_values($input['allocations']) : [];
        if ($allocations === []) {
            throw new DomainError('E_OPENITEM_UNKNOWN', 'settle without allocations');
        }

        /** @var list<array{item: OpenItem, settlement: Settlement}> $plan */
        $plan = [];
        /** @var array<string, Money> $planned amounts already allocated per item */
        $planned = [];

        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                throw new DomainError('E_OPENITEM_UNKNOWN', 'Allocation is not a structure');
            }

            $openItemId = $allocation['openItemId'] ?? null;
            $item = null;
            if (is_string($openItemId)) {
                try {
                    $item = $this->openItems->byId(Uuid::fromString($openItemId));
                } catch (InvalidValue) {
                    $item = null;
                }
            }

            if ($item === null) {
                throw new DomainError('E_OPENITEM_UNKNOWN', sprintf(
                    'Open item %s does not exist',
                    is_string($openItemId) ? $openItemId : '?',
                ));
            }

            $money = $this->parseSettlementMoney($allocation['money'] ?? null, 'Allocation amount');
            [$differenceMoney, $differenceKind] = $this->parseDifference($allocation['difference'] ?? null, $item);

            // Validate fully first, then apply — no partial state.
            $alreadyPlanned = $planned[$item->id->value] ?? Money::zero($this->baseCurrency);
            if ($money->add($alreadyPlanned)->compareTo($item->remaining()) > 0) {
                throw new DomainError('E_SETTLEMENT_EXCEEDS_ITEM', sprintf(
                    'Allocation %s exceeds remaining amount %s of item %s',
                    $money->amountAsString(),
                    $item->remaining()->subtract($alreadyPlanned)->amountAsString(),
                    $item->id->value,
                ), ['openItemId' => $item->id->value]);
            }

            $planned[$item->id->value] = $money->add($alreadyPlanned);
            $plan[] = [
                'item' => $item,
                'settlement' => new Settlement($entry->id, $money, $entry->entryDate, $differenceMoney, $differenceKind),
            ];
        }

        $affected = [];

        foreach ($plan as $step) {
            $before = $step['item']->remaining()->amountAsString();
            $step['item']->settle($step['settlement']);
            $this->openItems->save($step['item']);
            $this->recordAudit($actor, 'openItem', $step['item']->id, 'settled', [
                'remaining' => ['from' => $before, 'to' => $step['item']->remaining()->amountAsString()],
            ]);
            $affected[] = $step['item'];
        }

        return $affected;
    }

    private function parseSettlementMoney(mixed $raw, string $label): Money
    {
        $amount = is_array($raw) && is_string($raw['amount'] ?? null) ? $raw['amount'] : null;
        $currency = is_array($raw) && is_string($raw['currency'] ?? null) ? $raw['currency'] : null;

        if ($amount === null || $currency !== $this->baseCurrency->code) {
            throw new InvalidValue(sprintf('%s missing or wrong currency', $label));
        }

        $money = Money::of($amount, $this->baseCurrency);

        if (!$money->isPositive()) {
            throw new InvalidValue(sprintf('%s must be > 0', $label));
        }

        return $money;
    }

    /**
     * @return array{0: ?Money, 1: ?SettlementDifferenceKind}
     */
    private function parseDifference(mixed $raw, OpenItem $item): array
    {
        if ($raw === null) {
            return [null, null];
        }

        if (!is_array($raw)) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'difference is not a structure');
        }

        $kind = SettlementDifferenceKind::tryFrom(is_string($raw['kind'] ?? null) ? $raw['kind'] : '');
        if ($kind === null) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', sprintf(
                'Unknown difference kind "%s"',
                is_string($raw['kind'] ?? null) ? $raw['kind'] : '?',
            ));
        }

        try {
            $money = $this->parseSettlementMoney($raw['money'] ?? null, 'Difference amount');
        } catch (InvalidValue) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'Difference amount invalid (≤ 0 or format)');
        }

        if ($money->compareTo($item->remaining()) > 0) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', sprintf(
                'Difference %s exceeds remaining amount %s',
                $money->amountAsString(),
                $item->remaining()->amountAsString(),
            ));
        }

        return [$money, $kind];
    }

    /**
     * Correction only in status `entered`, with audit trail — no deletion
     * (decision 2026-06-07, GoBD-conservative).
     *
     * @param array<string, mixed> $input
     */
    public function correct(array $input): JournalEntry
    {
        $actor = $this->actor($input);
        $entry = $this->requireEntry($input['entryId'] ?? null);

        $changes = [];

        if (is_string($input['text'] ?? null) && $input['text'] !== $entry->text()) {
            $changes['text'] = ['from' => $entry->text(), 'to' => $input['text']];
            $entry->changeText($input['text']);
        }

        if (is_array($input['lines'] ?? null)) {
            /** @var list<array{account: string, side: Side, money: Money, dimensions: list<DimensionValue>, taxTag: array<string, mixed>|null}> $parsed */
            $parsed = [];
            if (count($input['lines']) < 2) {
                throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'A posting needs at least two lines');
            }

            foreach (array_values($input['lines']) as $index => $rawLine) {
                if (!is_array($rawLine)) {
                    throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Line %d is not a structure', $index));
                }

                $parsed[] = $this->parseLine($rawLine, $index);
            }

            $lines = $this->resolveLines($parsed);
            $this->assertBalanced($lines);

            $changes['lines'] = [
                'from' => array_map(static fn (EntryLine $line): array => $line->jsonSerialize(), $entry->lines()),
                'to' => array_map(static fn (EntryLine $line): array => $line->jsonSerialize(), $lines),
            ];
            $entry->changeLines($lines);
        }

        if ($changes !== []) {
            $this->journal->save($entry);
            $this->recordAudit($actor, 'journalEntry', $entry->id, 'corrected', $changes);
        } else {
            // Status check even without an effective change (E_ENTRY_FINALIZED)
            $entry->changeText($entry->text());
        }

        return $entry;
    }

    /**
     * Finalize individually (`entryId`) or as a bulk trigger
     * (`finalizeUntil`: all entered postings up to and including the date).
     *
     * @param array<string, mixed> $input
     *
     * @return int number of finalized postings
     */
    public function finalize(array $input): int
    {
        $actor = $this->actor($input);

        if (isset($input['entryId'])) {
            $entry = $this->requireEntry($input['entryId']);

            if ($entry->isFinalized()) {
                return 0;
            }

            $entry->finalize();
            $this->journal->save($entry);
            $this->recordAudit($actor, 'journalEntry', $entry->id, 'finalized', [
                'status' => ['from' => EntryStatus::Entered->value, 'to' => EntryStatus::Finalized->value],
            ]);

            return 1;
        }

        $until = $input['finalizeUntil'] ?? null;
        if (!is_string($until)) {
            throw new DomainError('E_ENTRY_UNKNOWN', 'finalize needs entryId or finalizeUntil');
        }

        $untilDate = $this->parseEntryDate($until);
        $count = 0;

        foreach ($this->journal->all() as $entry) {
            if ($entry->isFinalized() || $entry->entryDate->isAfter($untilDate)) {
                continue;
            }

            $entry->finalize();
            $this->journal->save($entry);
            $this->recordAudit($actor, 'journalEntry', $entry->id, 'finalized', [
                'status' => ['from' => EntryStatus::Entered->value, 'to' => EntryStatus::Finalized->value],
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Reversal = new posting with back-reference, general reversal (v0.3/M4):
     * same accounts, same sides, negated amounts — turnover figures
     * stay un-inflated. Reversing a reversal is allowed (api.md).
     *
     * @param array<string, mixed> $input
     */
    public function reverse(array $input): JournalEntry
    {
        $actor = $this->actor($input);
        $original = $this->requireEntry($input['entryId'] ?? null);

        if ($original->reversedBy() !== null) {
            throw new DomainError('E_ENTRY_ALREADY_REVERSED', sprintf(
                'Posting %s is already reversed',
                $original->id->value,
            ), ['entryId' => $original->id->value]);
        }

        $entryDate = $this->parseEntryDate($input['entryDate'] ?? null);
        [$fiscalYear, $period] = $this->openPeriodFor($entryDate);

        $text = is_string($input['text'] ?? null) ? $input['text'] : sprintf('Reversal %d', $original->sequenceNumber);

        $reversal = new JournalEntry(
            $this->ids->next(),
            $this->journal->nextSequenceNumber($fiscalYear->year),
            $entryDate,
            $original->voucherDate,
            $this->clock->now(),
            new PeriodRef($fiscalYear->year, $period->number),
            $original->voucherId,
            $text,
            array_map(static fn (EntryLine $line): EntryLine => $line->negated(), $original->lines()),
            reverses: $original->id,
        );

        $original->markReversed($reversal->id);
        $this->journal->append($reversal);
        $this->journal->save($original);

        $this->recordAudit($actor, 'journalEntry', $reversal->id, 'created');
        $this->recordAudit($actor, 'journalEntry', $original->id, 'reversed', [
            'reversedBy' => ['from' => null, 'to' => $reversal->id->value],
        ]);

        return $reversal;
    }

    /** @param array<string, mixed> $input */
    public function closePeriod(array $input): Period
    {
        $fiscalYear = $this->requireFiscalYear($input['fiscalYear'] ?? null);
        $period = $fiscalYear->closePeriod($this->periodNumber($input));
        $this->fiscalYears->save($fiscalYear);

        return $period;
    }

    /** @param array<string, mixed> $input */
    public function reopenPeriod(array $input): Period
    {
        $fiscalYear = $this->requireFiscalYear($input['fiscalYear'] ?? null);
        $period = $fiscalYear->reopenPeriod($this->periodNumber($input));
        $this->fiscalYears->save($fiscalYear);

        return $period;
    }

    /**
     * Pure status change with preconditions: all periods closed,
     * all postings finalized (api.md v0.3) — NO closing entries.
     *
     * @param array<string, mixed> $input
     */
    public function closeFiscalYear(array $input): FiscalYear
    {
        $fiscalYear = $this->requireFiscalYear($input['fiscalYear'] ?? null);

        foreach ($this->journal->forFiscalYear($fiscalYear->year) as $entry) {
            if (!$entry->isFinalized()) {
                throw new DomainError('E_FISCALYEAR_UNFINALIZED_ENTRIES', sprintf(
                    'Year-end close %d: posting %d is not finalized',
                    $fiscalYear->year,
                    $entry->sequenceNumber,
                ), ['fiscalYear' => $fiscalYear->year, 'sequenceNumber' => $entry->sequenceNumber]);
            }
        }

        $fiscalYear->close();
        $this->fiscalYears->save($fiscalYear);

        return $fiscalYear;
    }

    /**
     * Create fiscal year (v0.4): overlap with existing years
     * is rejected (E_FISCALYEAR_OVERLAP); gaps are allowed.
     * Without explicit periods: 12 monthly periods.
     *
     * @param array<string, mixed> $input
     */
    public function createFiscalYear(array $input): FiscalYear
    {
        $year = is_int($input['year'] ?? null) ? $input['year'] : 0;
        $start = $this->parseEntryDate($input['start'] ?? null);
        $end = $this->parseEntryDate($input['end'] ?? null);

        foreach ($this->fiscalYears->all() as $existing) {
            $overlaps = !$existing->end->isBefore($start) && !$existing->start->isAfter($end);

            if ($overlaps || $existing->year === $year) {
                throw new DomainError('E_FISCALYEAR_OVERLAP', sprintf(
                    'Fiscal year %d (%s to %s) overlaps with %d',
                    $year,
                    $start->iso,
                    $end->iso,
                    $existing->year,
                ), ['year' => $year, 'existing' => $existing->year]);
            }
        }

        $fiscalYear = FiscalYear::create($this->ids->next(), $year, $start, $end);
        $this->fiscalYears->add($fiscalYear);

        return $fiscalYear;
    }

    /** @param array<string, mixed> $input */
    public function createAccount(array $input): Account
    {
        $actor = $this->actor($input);
        $account = $this->buildAccount($input);

        if ($this->accounts->byNumber($account->number) !== null) {
            throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', sprintf(
                'Account number %s is already taken',
                $account->number->value,
            ), ['number' => $account->number->value]);
        }

        $this->accounts->add($account);
        $this->recordAudit($actor, 'account', $account->id, 'created');

        return $account;
    }

    /** @param array<string, mixed> $input */
    public function lockAccount(array $input): Account
    {
        $actor = $this->actor($input);
        $number = is_string($input['number'] ?? null) ? $input['number'] : '';
        $account = $this->accounts->byNumber(AccountNumber::of($number));

        if ($account === null) {
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf('Account %s does not exist', $number), ['number' => $number]);
        }

        $before = $account->status()->value;
        $account->lock();
        $this->accounts->save($account);
        $this->recordAudit($actor, 'account', $account->id, 'locked', [
            'status' => ['from' => $before, 'to' => $account->status()->value],
        ]);

        return $account;
    }

    /**
     * Chart-of-accounts import (DATEV-compatible rows): atomic — validate
     * everything first, then create.
     *
     * @param array<string, mixed> $input
     *
     * @return int number of imported accounts
     */
    public function importChartOfAccounts(array $input): int
    {
        $actor = $this->actor($input);
        $rows = $input['rows'] ?? null;

        if (!is_array($rows) || $rows === []) {
            throw new DomainError('E_COA_FORMAT_INVALID', 'Import without rows');
        }

        $accounts = [];
        $numbers = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                throw new DomainError('E_COA_FORMAT_INVALID', sprintf('Row %d is not a structure', $index));
            }

            try {
                $account = $this->buildAccount($row);
            } catch (DomainError) {
                throw new DomainError('E_COA_FORMAT_INVALID', sprintf('Row %d is not parsable', $index), ['row' => $index]);
            }

            if (isset($numbers[$account->number->value]) || $this->accounts->byNumber($account->number) !== null) {
                throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', sprintf(
                    'Account number %s is already taken',
                    $account->number->value,
                ), ['number' => $account->number->value]);
            }

            $numbers[$account->number->value] = true;
            $accounts[] = $account;
        }

        foreach ($accounts as $account) {
            $this->accounts->add($account);
            $this->recordAudit($actor, 'account', $account->id, 'created');
        }

        return count($accounts);
    }

    // ---- internal --------------------------------------------------------

    /** @param array<string, mixed> $input */
    private function actor(array $input): string
    {
        return is_string($input['actor'] ?? null) && $input['actor'] !== '' ? $input['actor'] : 'system';
    }

    /**
     * @param array<mixed> $rawLine
     *
     * @return array{account: string, side: Side, money: Money, dimensions: list<DimensionValue>, taxTag: array<string, mixed>|null}
     */
    private function parseLine(array $rawLine, int $index): array
    {
        $money = $rawLine['money'] ?? null;
        $amount = is_array($money) && is_string($money['amount'] ?? null) ? $money['amount'] : null;
        $currency = is_array($money) && is_string($money['currency'] ?? null) ? $money['currency'] : null;

        if ($amount === null || $currency === null) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Line %d: money missing or incomplete', $index));
        }

        if ($currency !== $this->baseCurrency->code) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf(
                'Line %d: foreign currency %s — v1 posts only the tenant currency %s',
                $index,
                $currency,
                $this->baseCurrency->code,
            ), ['currency' => $currency]);
        }

        try {
            $parsedMoney = Money::of($amount, $this->baseCurrency);
        } catch (InvalidValue) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf(
                'Line %d: amount "%s" is not a valid %s amount',
                $index,
                $amount,
                $this->baseCurrency->code,
            ), ['amount' => $amount]);
        }

        if (!$parsedMoney->isPositive()) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf(
                'Line %d: amount must be > 0 (negative amounts only on reversal)',
                $index,
            ), ['amount' => $amount]);
        }

        $side = Side::tryFrom(is_string($rawLine['side'] ?? null) ? $rawLine['side'] : '');
        if ($side === null) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Line %d: side must be debit or credit', $index));
        }

        $account = $rawLine['account'] ?? null;
        if (!is_string($account) || $account === '') {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Line %d: account missing', $index));
        }

        $dimensions = [];
        foreach (is_array($rawLine['dimensions'] ?? null) ? $rawLine['dimensions'] : [] as $rawDimension) {
            if (
                !is_array($rawDimension)
                || !is_string($rawDimension['type'] ?? null)
                || !is_string($rawDimension['code'] ?? null)
            ) {
                throw new DomainError('E_DIMENSION_INVALID', sprintf('Line %d: dimension incomplete', $index));
            }

            $dimensions[] = DimensionValue::of($rawDimension['type'], $rawDimension['code']);
        }

        /** @var array<string, mixed>|null $taxTag */
        $taxTag = is_array($rawLine['taxTag'] ?? null) ? $rawLine['taxTag'] : null;

        return [
            'account' => $account,
            'side' => $side,
            'money' => $parsedMoney,
            'dimensions' => $dimensions,
            'taxTag' => $taxTag,
        ];
    }

    private function requireVoucher(mixed $voucherId): Voucher
    {
        if (!is_string($voucherId) || $voucherId === '') {
            throw new DomainError('E_ENTRY_NO_VOUCHER', 'No posting without a voucher (F-CORE-003)');
        }

        try {
            $voucher = $this->vouchers->byId(Uuid::fromString($voucherId));
        } catch (InvalidValue) {
            $voucher = null;
        }

        if ($voucher === null) {
            // v0.5/F-001: a set but unknown voucherId has its own
            // code (reference step, after "voucherId missing").
            throw new DomainError('E_VOUCHER_UNKNOWN', sprintf(
                'Voucher %s does not exist',
                $voucherId,
            ), ['voucherId' => $voucherId]);
        }

        return $voucher;
    }

    /**
     * @param list<array{account: string, side: Side, money: Money, dimensions: list<DimensionValue>, taxTag: array<string, mixed>|null}> $parsed
     *
     * @return list<EntryLine>
     */
    private function resolveLines(array $parsed): array
    {
        $lines = [];

        foreach ($parsed as $line) {
            $number = AccountNumber::of($line['account']);
            $account = $this->accounts->byNumber($number);

            if ($account === null) {
                throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf(
                    'Account %s does not exist',
                    $number->value,
                ), ['number' => $number->value]);
            }

            if ($account->isLocked()) {
                throw new DomainError('E_ACCOUNT_LOCKED', sprintf(
                    'Account %s is locked',
                    $number->value,
                ), ['number' => $number->value]);
            }

            $lines[] = new EntryLine($account->id, $account->number, $line['side'], $line['money'], $line['dimensions'], $line['taxTag']);
        }

        foreach ($lines as $entryLine) {
            $this->dimensions->validateLine($entryLine->account, $entryLine->dimensions);
        }

        return $lines;
    }

    /** @param list<EntryLine> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = Money::zero($this->baseCurrency);
        $credit = Money::zero($this->baseCurrency);

        foreach ($lines as $line) {
            if ($line->side === Side::Debit) {
                $debit = $debit->add($line->money);
            } else {
                $credit = $credit->add($line->money);
            }
        }

        if (!$debit->equals($credit)) {
            throw new DomainError('E_ENTRY_UNBALANCED', sprintf(
                'Σ debit (%s) ≠ Σ credit (%s)',
                $debit->amountAsString(),
                $credit->amountAsString(),
            ), ['debit' => $debit->amountAsString(), 'credit' => $credit->amountAsString()]);
        }
    }

    private function parseEntryDate(mixed $entryDate): CalendarDate
    {
        if (!is_string($entryDate)) {
            throw new DomainError('E_PERIOD_UNKNOWN', 'entryDate missing');
        }

        try {
            return CalendarDate::of($entryDate);
        } catch (InvalidValue) {
            throw new DomainError('E_PERIOD_UNKNOWN', sprintf('Invalid posting date "%s"', $entryDate));
        }
    }

    /**
     * @return array{0: FiscalYear, 1: Period}
     */
    private function openPeriodFor(CalendarDate $entryDate): array
    {
        $fiscalYear = $this->fiscalYears->forDate($entryDate);

        if ($fiscalYear === null) {
            throw new DomainError('E_PERIOD_UNKNOWN', sprintf(
                'Posting date %s lies outside any created fiscal year',
                $entryDate->iso,
            ), ['date' => $entryDate->iso]);
        }

        $period = $fiscalYear->periodForDate($entryDate);

        if ($fiscalYear->isClosed() || !$period->isOpen()) {
            throw new DomainError('E_PERIOD_CLOSED', sprintf(
                'Period %d/%d is closed',
                $fiscalYear->year,
                $period->number,
            ), ['fiscalYear' => $fiscalYear->year, 'period' => $period->number]);
        }

        return [$fiscalYear, $period];
    }

    private function requireEntry(mixed $entryId): JournalEntry
    {
        $entry = null;

        if (is_string($entryId) && $entryId !== '') {
            try {
                $entry = $this->journal->byId(Uuid::fromString($entryId));
            } catch (InvalidValue) {
                $entry = null;
            }
        }

        return $entry ?? throw new DomainError('E_ENTRY_UNKNOWN', sprintf(
            'Posting %s does not exist',
            is_string($entryId) ? $entryId : '?',
        ));
    }

    private function requireFiscalYear(mixed $year): FiscalYear
    {
        $fiscalYear = is_int($year) ? $this->fiscalYears->byYear($year) : null;

        return $fiscalYear ?? throw new DomainError('E_PERIOD_UNKNOWN', sprintf(
            'Fiscal year %s is not created',
            is_int($year) ? (string) $year : '?',
        ));
    }

    /** @param array<string, mixed> $input */
    private function periodNumber(array $input): int
    {
        $period = $input['period'] ?? null;

        if (!is_int($period)) {
            throw new DomainError('E_PERIOD_UNKNOWN', 'Period number missing');
        }

        return $period;
    }

    /** @param array<mixed> $input */
    private function buildAccount(array $input): Account
    {
        $number = $input['number'] ?? null;
        $name = $input['name'] ?? null;
        $type = AccountType::tryFrom(is_string($input['type'] ?? null) ? $input['type'] : '');

        if (!is_string($number) || $number === '' || !is_string($name) || $name === '' || $type === null) {
            throw new DomainError('E_COA_FORMAT_INVALID', 'Account needs number, name and a valid type');
        }

        $subtype = is_string($input['subtype'] ?? null) ? $input['subtype'] : null;
        $status = ($input['status'] ?? null) === AccountStatus::Locked->value
            ? AccountStatus::Locked
            : AccountStatus::Active;

        return new Account($this->ids->next(), AccountNumber::of($number), $name, $type, $subtype, $status);
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function recordAudit(string $actor, string $objectType, Uuid $objectId, string $action, array $changes = []): void
    {
        $this->audit->append(new AuditRecord(
            $this->ids->next(),
            $this->clock->now(),
            $actor,
            $objectType,
            $objectId,
            $action,
            $changes,
        ));
    }
}
