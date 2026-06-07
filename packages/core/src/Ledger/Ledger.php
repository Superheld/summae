<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Port\AccountRepository;
use Rechnungswesen\Core\Port\AuditTrail;
use Rechnungswesen\Core\Port\FiscalYearRepository;
use Rechnungswesen\Core\Port\JournalRepository;
use Rechnungswesen\Core\Port\OpenItemRepository;
use Rechnungswesen\Core\Port\VoucherRepository;
use Rechnungswesen\Core\Shared\AccountNumber;
use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Clock;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\DimensionValue;
use Rechnungswesen\Core\Shared\Exception\InvalidValue;
use Rechnungswesen\Core\Shared\IdGenerator;
use Rechnungswesen\Core\Shared\Money;
use Rechnungswesen\Core\Shared\PeriodRef;
use Rechnungswesen\Core\Shared\Uuid;

/**
 * Domain Service `post` und Verwandte (ledger-modell.md):
 * berührt JournalEntry + FiscalYear + Journalnummer — deshalb Service.
 *
 * Prüfreihenfolge beim Buchen ist Vertragsbestandteil (api.md):
 * 1. Struktur (E_ENTRY_TOO_FEW_LINES, E_ENTRY_INVALID_AMOUNT)
 * 2. Referenzen (E_ENTRY_NO_VOUCHER, E_ACCOUNT_UNKNOWN, E_ACCOUNT_LOCKED,
 *    E_DIMENSION_INVALID)
 * 3. Bilanzgleichung (E_ENTRY_UNBALANCED)
 * 4. Zeitlicher Kontext (E_PERIOD_UNKNOWN, E_PERIOD_CLOSED)
 * Nur der erste Fehler wird gemeldet.
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

        // 1. Struktur
        $rawLines = $input['lines'] ?? null;
        if (!is_array($rawLines) || count($rawLines) < 2) {
            throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'Eine Buchung braucht mindestens zwei Positionen');
        }

        /** @var list<array{account: string, side: Side, money: Money, dimensions: list<DimensionValue>, taxTag: array<string, mixed>|null}> $parsed */
        $parsed = [];
        foreach (array_values($rawLines) as $index => $rawLine) {
            if (!is_array($rawLine)) {
                throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Position %d ist keine Struktur', $index));
            }

            $parsed[] = $this->parseLine($rawLine, $index);
        }

        // 2. Referenzen
        $voucher = $this->requireVoucher($input['voucherId'] ?? null);
        $lines = $this->resolveLines($parsed);

        // 3. Bilanzgleichung
        $this->assertBalanced($lines);

        // 4. Zeitlicher Kontext
        $entryDate = $this->parseEntryDate($input['entryDate'] ?? null);
        [$fiscalYear, $period] = $this->openPeriodFor($entryDate);

        $text = is_string($input['text'] ?? null) ? $input['text'] : '';

        $entry = new JournalEntry(
            $this->ids->next(),
            $this->journal->nextSequenceNumber($fiscalYear->year),
            $entryDate,
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
     * AR/AP-Automatik: Soll auf Forderungskonto -> receivable,
     * Haben auf Verbindlichkeitskonto -> payable (natürliche Saldoseite).
     * Stornobuchungen erzeugen keine neuen Posten.
     *
     * @return list<OpenItem>
     */
    private function createOpenItems(JournalEntry $entry): array
    {
        if ($entry->reverses !== null) {
            return [];
        }

        $created = [];

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
            );

            $this->openItems->add($item);
            $created[] = $item;
        }

        return $created;
    }

    /**
     * Ausgleich: Zuordnung Zahlung -> offene(r) Posten, auch teilweise;
     * immer explizit, kein FIFO-Automatismus (determinismus.md §3).
     * Differenzen (Skonto/Ausfall/Kleindifferenz) nach api.md G2 (v0.3).
     *
     * @param array<string, mixed> $input
     *
     * @return list<OpenItem> die betroffenen Posten
     */
    public function settle(array $input): array
    {
        $actor = $this->actor($input);
        $entry = $this->requireEntry($input['entryId'] ?? null);

        $allocations = is_array($input['allocations'] ?? null) ? array_values($input['allocations']) : [];
        if ($allocations === []) {
            throw new DomainError('E_OPENITEM_UNKNOWN', 'settle ohne Zuordnungen');
        }

        /** @var list<array{item: OpenItem, settlement: Settlement}> $plan */
        $plan = [];
        /** @var array<string, Money> $planned bereits verplante Beträge je Posten */
        $planned = [];

        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                throw new DomainError('E_OPENITEM_UNKNOWN', 'Zuordnung ist keine Struktur');
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
                    'Offener Posten %s existiert nicht',
                    is_string($openItemId) ? $openItemId : '?',
                ));
            }

            $money = $this->parseSettlementMoney($allocation['money'] ?? null, 'Zuordnungsbetrag');
            [$differenceMoney, $differenceKind] = $this->parseDifference($allocation['difference'] ?? null, $item);

            // Erst vollständig validieren, dann anwenden — kein Teilzustand.
            $alreadyPlanned = $planned[$item->id->value] ?? Money::zero($this->baseCurrency);
            if ($money->add($alreadyPlanned)->compareTo($item->remaining()) > 0) {
                throw new DomainError('E_SETTLEMENT_EXCEEDS_ITEM', sprintf(
                    'Zuordnung %s übersteigt Restbetrag %s des Postens %s',
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
            throw new InvalidValue(sprintf('%s fehlt oder falsche Währung', $label));
        }

        $money = Money::of($amount, $this->baseCurrency);

        if (!$money->isPositive()) {
            throw new InvalidValue(sprintf('%s muss > 0 sein', $label));
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
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'difference ist keine Struktur');
        }

        $kind = SettlementDifferenceKind::tryFrom(is_string($raw['kind'] ?? null) ? $raw['kind'] : '');
        if ($kind === null) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', sprintf(
                'Unbekannte Differenzart "%s"',
                is_string($raw['kind'] ?? null) ? $raw['kind'] : '?',
            ));
        }

        try {
            $money = $this->parseSettlementMoney($raw['money'] ?? null, 'Differenzbetrag');
        } catch (InvalidValue) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'Differenzbetrag ungültig (≤ 0 oder Format)');
        }

        if ($money->compareTo($item->remaining()) > 0) {
            throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', sprintf(
                'Differenz %s übersteigt Restbetrag %s',
                $money->amountAsString(),
                $item->remaining()->amountAsString(),
            ));
        }

        return [$money, $kind];
    }

    /**
     * Korrektur nur im Status `entered`, mit Audit-Trail — kein Löschen
     * (Entscheidung 2026-06-07, GoBD-konservativ).
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
                throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'Eine Buchung braucht mindestens zwei Positionen');
            }

            foreach (array_values($input['lines']) as $index => $rawLine) {
                if (!is_array($rawLine)) {
                    throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Position %d ist keine Struktur', $index));
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
            // Statusprüfung auch ohne effektive Änderung (E_ENTRY_FINALIZED)
            $entry->changeText($entry->text());
        }

        return $entry;
    }

    /**
     * Festschreiben einzeln (`entryId`) oder als Massenauslöser
     * (`finalizeUntil`: alle erfassten Buchungen bis einschließlich Datum).
     *
     * @param array<string, mixed> $input
     *
     * @return int Anzahl festgeschriebener Buchungen
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
            throw new DomainError('E_ENTRY_UNKNOWN', 'finalize braucht entryId oder finalizeUntil');
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
     * Storno = neue Buchung mit Rückverweis, Generalumkehr (v0.3/M4):
     * gleiche Konten, gleiche Seiten, negierte Beträge — Verkehrszahlen
     * bleiben unaufgebläht. Storno eines Stornos ist zulässig (api.md).
     *
     * @param array<string, mixed> $input
     */
    public function reverse(array $input): JournalEntry
    {
        $actor = $this->actor($input);
        $original = $this->requireEntry($input['entryId'] ?? null);

        if ($original->reversedBy() !== null) {
            throw new DomainError('E_ENTRY_ALREADY_REVERSED', sprintf(
                'Buchung %s ist bereits storniert',
                $original->id->value,
            ), ['entryId' => $original->id->value]);
        }

        $entryDate = $this->parseEntryDate($input['entryDate'] ?? null);
        [$fiscalYear, $period] = $this->openPeriodFor($entryDate);

        $text = is_string($input['text'] ?? null) ? $input['text'] : sprintf('Storno %d', $original->sequenceNumber);

        $reversal = new JournalEntry(
            $this->ids->next(),
            $this->journal->nextSequenceNumber($fiscalYear->year),
            $entryDate,
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
     * Reiner Statuswechsel mit Voraussetzungen: alle Perioden geschlossen,
     * alle Buchungen festgeschrieben (api.md v0.3) — KEINE Abschlussbuchungen.
     *
     * @param array<string, mixed> $input
     */
    public function closeFiscalYear(array $input): FiscalYear
    {
        $fiscalYear = $this->requireFiscalYear($input['fiscalYear'] ?? null);

        foreach ($this->journal->forFiscalYear($fiscalYear->year) as $entry) {
            if (!$entry->isFinalized()) {
                throw new DomainError('E_PERIOD_OUT_OF_ORDER', sprintf(
                    'Jahresabschluss %d: Buchung %d ist nicht festgeschrieben',
                    $fiscalYear->year,
                    $entry->sequenceNumber,
                ), ['fiscalYear' => $fiscalYear->year, 'sequenceNumber' => $entry->sequenceNumber]);
            }
        }

        $fiscalYear->close();
        $this->fiscalYears->save($fiscalYear);

        return $fiscalYear;
    }

    /** @param array<string, mixed> $input */
    public function createAccount(array $input): Account
    {
        $actor = $this->actor($input);
        $account = $this->buildAccount($input);

        if ($this->accounts->byNumber($account->number) !== null) {
            throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', sprintf(
                'Kontonummer %s ist bereits vergeben',
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
            throw new DomainError('E_ACCOUNT_UNKNOWN', sprintf('Konto %s existiert nicht', $number), ['number' => $number]);
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
     * Kontenrahmen-Import (DATEV-kompatible Zeilen): atomar — erst alles
     * validieren, dann anlegen.
     *
     * @param array<string, mixed> $input
     *
     * @return int Anzahl importierter Konten
     */
    public function importChartOfAccounts(array $input): int
    {
        $actor = $this->actor($input);
        $rows = $input['rows'] ?? null;

        if (!is_array($rows) || $rows === []) {
            throw new DomainError('E_COA_FORMAT_INVALID', 'Import ohne Zeilen');
        }

        $accounts = [];
        $numbers = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                throw new DomainError('E_COA_FORMAT_INVALID', sprintf('Zeile %d ist keine Struktur', $index));
            }

            try {
                $account = $this->buildAccount($row);
            } catch (DomainError) {
                throw new DomainError('E_COA_FORMAT_INVALID', sprintf('Zeile %d ist nicht parsebar', $index), ['row' => $index]);
            }

            if (isset($numbers[$account->number->value]) || $this->accounts->byNumber($account->number) !== null) {
                throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', sprintf(
                    'Kontonummer %s ist bereits vergeben',
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

    // ---- intern ----------------------------------------------------------

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
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Position %d: money fehlt oder unvollständig', $index));
        }

        if ($currency !== $this->baseCurrency->code) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf(
                'Position %d: Fremdwährung %s — v1 bucht nur Mandantenwährung %s',
                $index,
                $currency,
                $this->baseCurrency->code,
            ), ['currency' => $currency]);
        }

        try {
            $parsedMoney = Money::of($amount, $this->baseCurrency);
        } catch (InvalidValue) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf(
                'Position %d: Betrag "%s" ist kein gültiger %s-Betrag',
                $index,
                $amount,
                $this->baseCurrency->code,
            ), ['amount' => $amount]);
        }

        if (!$parsedMoney->isPositive()) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf(
                'Position %d: Betrag muss > 0 sein (negative Beträge nur bei Storno)',
                $index,
            ), ['amount' => $amount]);
        }

        $side = Side::tryFrom(is_string($rawLine['side'] ?? null) ? $rawLine['side'] : '');
        if ($side === null) {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Position %d: side muss debit oder credit sein', $index));
        }

        $account = $rawLine['account'] ?? null;
        if (!is_string($account) || $account === '') {
            throw new DomainError('E_ENTRY_INVALID_AMOUNT', sprintf('Position %d: account fehlt', $index));
        }

        $dimensions = [];
        foreach (is_array($rawLine['dimensions'] ?? null) ? $rawLine['dimensions'] : [] as $rawDimension) {
            if (
                !is_array($rawDimension)
                || !is_string($rawDimension['type'] ?? null)
                || !is_string($rawDimension['code'] ?? null)
            ) {
                throw new DomainError('E_DIMENSION_INVALID', sprintf('Position %d: Dimension unvollständig', $index));
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
            throw new DomainError('E_ENTRY_NO_VOUCHER', 'Keine Buchung ohne Beleg (F-CORE-003)');
        }

        try {
            $voucher = $this->vouchers->byId(Uuid::fromString($voucherId));
        } catch (InvalidValue) {
            $voucher = null;
        }

        if ($voucher === null) {
            // Spec nennt keinen eigenen Code für unbekannte Belege —
            // nächstplausibel ist E_ENTRY_NO_VOUCHER (siehe SPEC-FINDINGS).
            throw new DomainError('E_ENTRY_NO_VOUCHER', sprintf(
                'Beleg %s existiert nicht',
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
                    'Konto %s existiert nicht',
                    $number->value,
                ), ['number' => $number->value]);
            }

            if ($account->isLocked()) {
                throw new DomainError('E_ACCOUNT_LOCKED', sprintf(
                    'Konto %s ist gesperrt',
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
                'Σ Soll (%s) ≠ Σ Haben (%s)',
                $debit->amountAsString(),
                $credit->amountAsString(),
            ), ['debit' => $debit->amountAsString(), 'credit' => $credit->amountAsString()]);
        }
    }

    private function parseEntryDate(mixed $entryDate): CalendarDate
    {
        if (!is_string($entryDate)) {
            throw new DomainError('E_PERIOD_UNKNOWN', 'entryDate fehlt');
        }

        try {
            return CalendarDate::of($entryDate);
        } catch (InvalidValue) {
            throw new DomainError('E_PERIOD_UNKNOWN', sprintf('Ungültiges Buchungsdatum "%s"', $entryDate));
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
                'Buchungsdatum %s liegt außerhalb angelegter Geschäftsjahre',
                $entryDate->iso,
            ), ['date' => $entryDate->iso]);
        }

        $period = $fiscalYear->periodForDate($entryDate);

        if ($fiscalYear->isClosed() || !$period->isOpen()) {
            throw new DomainError('E_PERIOD_CLOSED', sprintf(
                'Periode %d/%d ist geschlossen',
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
            'Buchung %s existiert nicht',
            is_string($entryId) ? $entryId : '?',
        ));
    }

    private function requireFiscalYear(mixed $year): FiscalYear
    {
        $fiscalYear = is_int($year) ? $this->fiscalYears->byYear($year) : null;

        return $fiscalYear ?? throw new DomainError('E_PERIOD_UNKNOWN', sprintf(
            'Geschäftsjahr %s ist nicht angelegt',
            is_int($year) ? (string) $year : '?',
        ));
    }

    /** @param array<string, mixed> $input */
    private function periodNumber(array $input): int
    {
        $period = $input['period'] ?? null;

        if (!is_int($period)) {
            throw new DomainError('E_PERIOD_UNKNOWN', 'Periodennummer fehlt');
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
            throw new DomainError('E_COA_FORMAT_INVALID', 'Konto braucht number, name und gültigen type');
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
