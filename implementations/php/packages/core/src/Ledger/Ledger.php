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
use Summae\Core\Substrate\EntryLine;
use Summae\Core\Substrate\EntryStatus;
use Summae\Core\Substrate\FiscalYear;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\OpenItemKind;
use Summae\Core\Substrate\Period;
use Summae\Core\Substrate\PostResult;
use Summae\Core\Substrate\SettlementCause;
use Summae\Core\Substrate\Side;
use Summae\Core\Records\OpenItem;
use Summae\Core\Records\Voucher;
use Summae\Core\Policies\Constraint\DimensionRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
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
 *
 * Ledger keeps the operations that write postings — `post`, `correct`, `finalize`, `reverse` —
 * together with the line parsing they share, and is a thin facade for the three areas that were
 * only ever neighbours of the journal, not part of it: settlement (SettlementService), chart of
 * accounts (ChartAdminService) and fiscal years/periods (FiscalPeriodService). The facade is
 * deliberate: TenantOperations and every adapter keep talking to one object, so the split is a
 * change of shape inside the core and of nothing else.
 */
final readonly class Ledger
{
    private AuditWriter $auditWriter;
    private SettlementService $settlements;
    private ChartAdminService $chart;
    private FiscalPeriodService $periods;

    public function __construct(
        private Currency $baseCurrency,
        private AccountRepository $accounts,
        private FiscalYearRepository $fiscalYears,
        private VoucherRepository $vouchers,
        private JournalRepository $journal,
        private OpenItemRepository $openItems,
        AuditTrail $audit,
        private DimensionRegistry $dimensions,
        Clock $clock,
        private IdGenerator $ids,
        /** Null = unwired; treated as an EMPTY registry, so a caller-supplied tax tag is
         *  rejected rather than waved through — same behaviour as Node's default. */
        private ?TaxCodeRegistry $taxCodes = null,
    ) {
        $this->auditWriter = new AuditWriter($audit, $clock, $ids);
        $this->settlements = new SettlementService($baseCurrency, $accounts, $journal, $openItems, $this->auditWriter);
        $this->chart = new ChartAdminService($accounts, $ids, $this->auditWriter);
        $this->periods = new FiscalPeriodService($fiscalYears, $journal, $ids);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function post(array $input): PostResult
    {
        $actor = $this->auditWriter->actorOf($input);

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
        $entryDate = Lookups::parseEntryDate($input['entryDate'] ?? null);
        [$fiscalYear, $period] = $this->openPeriodFor($entryDate);

        $text = is_string($input['text'] ?? null) ? $input['text'] : '';

        $entry = new JournalEntry(
            $this->ids->next(),
            $this->journal->nextSequenceNumber($fiscalYear->year),
            $entryDate,
            $voucher->voucherDate,
            $this->auditWriter->now(),
            new PeriodRef($fiscalYear->year, $period->number),
            $voucher->id,
            $text,
            $lines,
        );

        $this->journal->append($entry);
        $this->auditWriter->record($actor, 'journalEntry', $entry->id, 'created');

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
     * Correction only in status `entered`, with audit trail — no deletion
     * (decision 2026-06-07, integrity-conservative).
     *
     * @param array<string, mixed> $input
     */
    public function correct(array $input): JournalEntry
    {
        $actor = $this->auditWriter->actorOf($input);
        $entry = Lookups::requireEntry($this->journal, $input['entryId'] ?? null);

        $changes = [];

        // Reading both fields leniently made every unrecognized field a silent no-op that still
        // returned the entry as a SUCCESS payload: `txt` instead of `text` looked like a correction
        // that had happened. A correction that changes nothing was never asked for — say so instead
        // of confirming a change nobody made.
        $hasText = ($input['text'] ?? null) !== null;
        $hasLines = ($input['lines'] ?? null) !== null;

        if (!$hasText && !$hasLines) {
            $fields = array_keys($input);
            sort($fields);

            throw new DomainError(
                'E_INPUT_INVALID',
                'correct requires "text" or "lines" — nothing to change',
                ['fields' => implode(',', $fields)],
            );
        }

        if ($hasText && !is_string($input['text'])) {
            throw new DomainError('E_INPUT_INVALID', 'correct: "text" must be a string');
        }

        // A JSON object decodes to an associative array here but is not an array in Node —
        // requiring a list keeps both languages judging the same input the same way.
        if ($hasLines && (!is_array($input['lines']) || !array_is_list($input['lines']))) {
            throw new DomainError('E_INPUT_INVALID', 'correct: "lines" must be an array');
        }

        if (is_string($input['text'] ?? null) && $input['text'] !== $entry->text()) {
            $changes['text'] = ['from' => $entry->text(), 'to' => $input['text']];
            $entry->changeText($input['text']);
        }

        if (is_array($input['lines'] ?? null)) {
            // Rewriting the lines used to leave the open items derived from them untouched, so the
            // subledger went on naming an amount, an account and a due date from a posting that no
            // longer existed — the same silent split between ledger and subledger as R-1, from the
            // other side. The text stays correctable; for amounts the GoBD-conform path is reversal
            // and a fresh posting, which keeps both books together.
            if ($this->openItems->byOriginEntry($entry->id) !== []) {
                throw new DomainError(
                    'E_ENTRY_HAS_OPEN_ITEMS',
                    'correct: this entry produced open items — correct the text, or reverse and post anew',
                    ['entryId' => $entry->id->value],
                );
            }

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
            $this->auditWriter->record($actor, 'journalEntry', $entry->id, 'corrected', $changes);
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
        $actor = $this->auditWriter->actorOf($input);

        if (isset($input['entryId'])) {
            $entry = Lookups::requireEntry($this->journal, $input['entryId']);

            if ($entry->isFinalized()) {
                return 0;
            }

            $entry->finalize();
            $this->journal->save($entry);
            $this->auditWriter->record($actor, 'journalEntry', $entry->id, 'finalized', [
                'status' => ['from' => EntryStatus::Entered->value, 'to' => EntryStatus::Finalized->value],
            ]);

            return 1;
        }

        $until = $input['finalizeUntil'] ?? null;
        if (!is_string($until)) {
            throw new DomainError('E_ENTRY_UNKNOWN', 'finalize needs entryId or finalizeUntil');
        }

        $untilDate = Lookups::parseEntryDate($until);
        $count = 0;

        foreach ($this->journal->all() as $entry) {
            if ($entry->isFinalized() || $entry->entryDate->isAfter($untilDate)) {
                continue;
            }

            $entry->finalize();
            $this->journal->save($entry);
            $this->auditWriter->record($actor, 'journalEntry', $entry->id, 'finalized', [
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
        $actor = $this->auditWriter->actorOf($input);
        $original = Lookups::requireEntry($this->journal, $input['entryId'] ?? null);

        if ($original->reversedBy() !== null) {
            throw new DomainError('E_ENTRY_ALREADY_REVERSED', sprintf(
                'Posting %s is already reversed',
                $original->id->value,
            ), ['entryId' => $original->id->value]);
        }

        // IMPL-008: a reversal clears the open items the reversed entry produced — but only while they
        // are untouched. Once one carries a settlement, money has actually moved, and cancelling the
        // item would drop that movement out of the open-item history while the ledger keeps it. The
        // line SAP draws with F5308: undo the settlement first, or post a credit note.
        $items = $this->openItems->byOriginEntry($original->id);
        foreach ($items as $item) {
            if ($item->settlements() !== []) {
                throw new DomainError(
                    'E_ENTRY_HAS_SETTLED_ITEMS',
                    'reverse: an open item of this entry is already settled — undo the settlement or post a credit note instead',
                    ['entryId' => $original->id->value, 'openItemId' => $item->id->value],
                );
            }
        }

        $entryDate = Lookups::parseEntryDate($input['entryDate'] ?? null);
        [$fiscalYear, $period] = $this->openPeriodFor($entryDate);

        $text = is_string($input['text'] ?? null) ? $input['text'] : sprintf('Reversal %d', $original->sequenceNumber);

        $reversal = new JournalEntry(
            $this->ids->next(),
            $this->journal->nextSequenceNumber($fiscalYear->year),
            $entryDate,
            $original->voucherDate,
            $this->auditWriter->now(),
            new PeriodRef($fiscalYear->year, $period->number),
            $original->voucherId,
            $text,
            array_map(static fn (EntryLine $line): EntryLine => $line->negated(), $original->lines()),
            reverses: $original->id,
        );

        $original->markReversed($reversal->id);
        $this->journal->append($reversal);
        $this->journal->save($original);

        $this->auditWriter->record($actor, 'journalEntry', $reversal->id, 'created');
        $this->auditWriter->record($actor, 'journalEntry', $original->id, 'reversed', [
            'reversedBy' => ['from' => null, 'to' => $reversal->id->value],
        ]);

        // Clear each untouched open item against the reversal. Nothing is deleted — the item keeps
        // its record and gains a settlement marked `cancellation`, which is what tells a reader (and
        // the cash-basis VAT return) that this was a reversal and not an incoming payment.
        foreach ($items as $item) {
            $item->settle(new Settlement(
                $reversal->id,
                $item->remaining(),
                $entryDate,
                null,
                null,
                SettlementCause::Cancellation,
            ));
            $this->openItems->save($item);
            $this->auditWriter->record($actor, 'openItem', $item->id, 'cancelled', [
                'cancelledBy' => ['from' => null, 'to' => $reversal->id->value],
            ]);
        }

        return $reversal;
    }

    // ---- facade: settlement, chart of accounts, fiscal years -------------

    /**
     * @param array<string, mixed> $input
     *
     * @return list<OpenItem> the affected items
     */
    public function settle(array $input): array
    {
        return $this->settlements->settle($input);
    }

    /** @param array<string, mixed> $input */
    public function createAccount(array $input): Account
    {
        return $this->chart->createAccount($input);
    }

    /** @param array<string, mixed> $input */
    public function lockAccount(array $input): Account
    {
        return $this->chart->lockAccount($input);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return int number of imported accounts
     */
    public function importChartOfAccounts(array $input): int
    {
        return $this->chart->importChartOfAccounts($input);
    }

    /** @param array<string, mixed> $input */
    public function createFiscalYear(array $input): FiscalYear
    {
        return $this->periods->createFiscalYear($input);
    }

    /** @param array<string, mixed> $input */
    public function closePeriod(array $input): Period
    {
        return $this->periods->closePeriod($input);
    }

    /** @param array<string, mixed> $input */
    public function reopenPeriod(array $input): Period
    {
        return $this->periods->reopenPeriod($input);
    }

    /** @param array<string, mixed> $input */
    public function closeFiscalYear(array $input): FiscalYear
    {
        return $this->periods->closeFiscalYear($input);
    }

    // ---- internal --------------------------------------------------------

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

        // A caller-supplied taxTag must name a REGISTERED tax code. The VAT return is built
        // from these tags, never from account numbers, so an unvalidated tag writes straight
        // into statutory output: `post` used to accept {"code":"MADEUP","reportingKey":"4711"}
        // and the invented key showed up as a line of the return. `postVoucher` always went
        // through the registry; the direct `post` path did not.
        /** @var array<string, mixed>|null $taxTag */
        $taxTag = is_array($rawLine['taxTag'] ?? null) ? $rawLine['taxTag'] : null;
        if ($taxTag !== null && is_string($taxTag['code'] ?? null) && $taxTag['code'] !== '') {
            ($this->taxCodes ?? TaxCodeRegistry::empty())->get($taxTag['code']);
        }

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
            // v0.5/SPEC-001: a set but unknown voucherId has its own
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
}
