<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Composition;

use Rechnungswesen\Core\Ledger\OpenItem;
use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Exception\InvalidValue;
use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Ledger\Voucher;
use Rechnungswesen\Core\Tenant;

/**
 * Anwendungsschicht-Komposition `postVoucher` (api.md, Teil der Spec!):
 * SF-02/03 in einem Aufruf — Beleg anlegen, expandTax, post, OP-Anlage.
 * Haupteinstiegspunkt für Apps und die CLI.
 */
final readonly class PostVoucherService
{
    public function __construct(
        private Tenant $tenant,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function post(array $input): array
    {
        $voucherData = is_array($input['voucher'] ?? null) ? $input['voucher'] : [];
        $voucherNumber = is_string($voucherData['voucherNumber'] ?? null) ? $voucherData['voucherNumber'] : '';
        $voucherDateRaw = is_string($voucherData['voucherDate'] ?? null) ? $voucherData['voucherDate'] : '';

        try {
            $voucherDate = CalendarDate::of($voucherDateRaw);
        } catch (InvalidValue) {
            throw new DomainError('E_ENTRY_NO_VOUCHER', 'postVoucher braucht voucher.voucherDate');
        }

        $voucher = new Voucher(
            $this->tenant->ids->next(),
            $voucherNumber,
            $voucherDate,
            is_string($voucherData['due'] ?? null) ? CalendarDate::of($voucherData['due']) : null,
            (bool) ($voucherData['recurring'] ?? false),
            is_int($voucherData['economicYear'] ?? null) ? $voucherData['economicYear'] : null,
        );
        $this->tenant->vouchers->add($voucher);

        $expansion = $this->tenant->tax->expand([
            'date' => $voucherDate->iso,
            'taxCode' => $input['taxCode'] ?? null,
            'direction' => $input['direction'] ?? 'output',
            'netLines' => $input['netLines'] ?? [],
        ]);

        $direction = ($input['direction'] ?? null) === 'input' ? 'input' : 'output';
        $counterAccount = is_string($input['counterAccount'] ?? null) ? $input['counterAccount'] : '';

        /** @var list<array<string, mixed>> $lines */
        $lines = [
            [
                'account' => $counterAccount,
                'side' => $direction === 'output' ? 'debit' : 'credit',
                'money' => $expansion['grossTotal'],
            ],
        ];

        /** @var list<array<string, mixed>> $netLines */
        $netLines = is_array($expansion['netLines']) ? $expansion['netLines'] : [];
        /** @var list<array<string, mixed>> $taxLines */
        $taxLines = is_array($expansion['taxLines']) ? $expansion['taxLines'] : [];

        foreach ([...$netLines, ...$taxLines] as $line) {
            $lines[] = $line;
        }

        $result = $this->tenant->ledger->post([
            'actor' => $input['actor'] ?? null,
            'entryDate' => $input['entryDate'] ?? $voucherDate->iso,
            'voucherId' => $voucher->id->value,
            'text' => $input['text'] ?? '',
            'lines' => $lines,
        ]);

        return [
            'entry' => $result->entry->jsonSerialize(),
            'openItemsCreated' => array_map(
                static fn (OpenItem $item): array => $item->jsonSerialize(),
                $result->openItemsCreated,
            ),
            'grossTotal' => $expansion['grossTotal'],
            'taxLines' => $expansion['taxLines'],
            'voucherId' => $voucher->id->value,
        ];
    }
}
