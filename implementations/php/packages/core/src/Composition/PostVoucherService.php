<?php

declare(strict_types=1);

namespace Summae\Core\Composition;

use Summae\Core\Records\OpenItem;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\DomainError;
use Summae\Core\Records\Voucher;
use Summae\Core\Tenant;

/**
 * Application-layer composition `postVoucher` (api.md, part of the spec!):
 * SF-02/03 in one call — create voucher, expandTax, post, OP creation.
 * Main entry point for apps and the CLI.
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
    /**
     * Build + store voucher from `input.voucher` — shared by postVoucher and createVoucher.
     *
     * @param array<string, mixed> $input
     */
    private function buildAndAddVoucher(array $input): Voucher
    {
        $voucherData = is_array($input['voucher'] ?? null) ? $input['voucher'] : [];
        $voucherDateRaw = is_string($voucherData['voucherDate'] ?? null) ? $voucherData['voucherDate'] : '';

        try {
            $voucherDate = CalendarDate::of($voucherDateRaw);
        } catch (InvalidValue) {
            throw new DomainError('E_ENTRY_NO_VOUCHER', 'Voucher needs voucher.voucherDate');
        }

        // v0.4: partner must exist before anything is created.
        $partnerId = null;
        if (isset($voucherData['partnerId'])) {
            $partnerId = $this->tenant->partnerService->require($voucherData['partnerId'])->id;
        }

        $serviceDate = is_string($voucherData['serviceDate'] ?? null)
            ? CalendarDate::of($voucherData['serviceDate'])
            : null;
        $servicePeriod = is_array($voucherData['servicePeriod'] ?? null) ? $voucherData['servicePeriod'] : [];

        $voucher = new Voucher(
            $this->tenant->ids->next(),
            is_string($voucherData['voucherNumber'] ?? null) ? $voucherData['voucherNumber'] : '',
            $voucherDate,
            is_string($voucherData['due'] ?? null) ? CalendarDate::of($voucherData['due']) : null,
            (bool) ($voucherData['recurring'] ?? false),
            is_int($voucherData['economicYear'] ?? null) ? $voucherData['economicYear'] : null,
            null,
            $serviceDate,
            is_string($servicePeriod['from'] ?? null) ? CalendarDate::of($servicePeriod['from']) : null,
            is_string($servicePeriod['to'] ?? null) ? CalendarDate::of($servicePeriod['to']) : null,
            is_string($voucherData['kind'] ?? null) ? $voucherData['kind'] : null,
            $partnerId,
            is_string($voucherData['issuer'] ?? null) ? $voucherData['issuer'] : null,
        );
        $this->tenant->vouchers->add($voucher);

        return $voucher;
    }

    /**
     * createVoucher: create a voucher without posting — makes pack-mode fixtures complete.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function createVoucher(array $input): array
    {
        $voucher = $this->buildAndAddVoucher($input);

        return ['id' => $voucher->id->value, 'voucherNumber' => $voucher->voucherNumber];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function post(array $input): array
    {
        $voucher = $this->buildAndAddVoucher($input);
        $voucherDate = $voucher->voucherDate;

        // Direct gross mode: explicit `lines` without tax expansion (e.g. payments).
        if (is_array($input['lines'] ?? null)) {
            $directResult = $this->tenant->ledger->post([
                'actor' => $input['actor'] ?? null,
                'entryDate' => $input['entryDate'] ?? $voucherDate->iso,
                'voucherId' => $voucher->id->value,
                'text' => $input['text'] ?? '',
                'lines' => $input['lines'],
            ]);

            return [
                'entry' => $directResult->entry->jsonSerialize(),
                'openItemsCreated' => array_map(
                    static fn (OpenItem $item): array => $item->jsonSerialize(),
                    $directResult->openItemsCreated,
                ),
                'voucherId' => $voucher->id->value,
            ];
        }

        $expansion = $this->tenant->tax->expand([
            'date' => $voucherDate->iso,
            'serviceDate' => $voucher->taxDate()->iso,
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
