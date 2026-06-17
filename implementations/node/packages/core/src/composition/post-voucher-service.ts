import { DomainError } from '../domain-error.js';
import { CalendarDate } from '../shared/calendar-date.js';
import { InvalidValue } from '../shared/errors.js';
import { Voucher } from '../ledger/voucher.js';
import type { Tenant } from './tenant.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * Anwendungsschicht-Komposition `postVoucher` (api.md, Teil der Spec): SF-02/03
 * in einem Aufruf — Beleg anlegen, expandTax, post, OP-Anlage.
 */
export class PostVoucherService {
  constructor(private readonly tenant: Tenant) {}

  post(input: Record<string, unknown>): Record<string, unknown> {
    const voucherData = isRecord(input.voucher) ? input.voucher : {};
    const voucherNumber = asString(voucherData.voucherNumber) ?? '';

    let voucherDate: CalendarDate;
    try {
      voucherDate = CalendarDate.of(asString(voucherData.voucherDate) ?? '');
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError('E_ENTRY_NO_VOUCHER', 'postVoucher braucht voucher.voucherDate');
      }
      throw error;
    }

    if (voucherData.partnerId !== undefined && voucherData.partnerId !== null) {
      // Partner-Stammdaten folgen im eigenen Slice.
      throw new DomainError('E_NOT_IMPLEMENTED', 'postVoucher mit partnerId folgt im Partner-Slice');
    }

    const date = (value: unknown): CalendarDate | null =>
      typeof value === 'string' ? CalendarDate.of(value) : null;
    const servicePeriod = isRecord(voucherData.servicePeriod) ? voucherData.servicePeriod : {};

    const voucher = new Voucher({
      id: this.tenant.ids.next(),
      voucherNumber,
      voucherDate,
      due: date(voucherData.due),
      recurring: voucherData.recurring === true,
      economicYear: typeof voucherData.economicYear === 'number' ? voucherData.economicYear : null,
      serviceDate: date(voucherData.serviceDate),
      servicePeriodFrom: date(servicePeriod.from),
      servicePeriodTo: date(servicePeriod.to),
      kind: asString(voucherData.kind),
      partnerId: null,
      issuer: asString(voucherData.issuer),
    });
    this.tenant.vouchers.add(voucher);

    const expansion = this.tenant.tax.expand({
      date: voucherDate.iso,
      serviceDate: voucher.taxDate().iso,
      taxCode: input.taxCode ?? null,
      direction: input.direction ?? 'output',
      netLines: input.netLines ?? [],
    });

    const direction = input.direction === 'input' ? 'input' : 'output';
    const counterAccount = asString(input.counterAccount) ?? '';
    const netLines = Array.isArray(expansion.netLines) ? expansion.netLines : [];
    const taxLines = Array.isArray(expansion.taxLines) ? expansion.taxLines : [];

    const lines: Array<Record<string, unknown>> = [
      {
        account: counterAccount,
        side: direction === 'output' ? 'debit' : 'credit',
        money: expansion.grossTotal,
      },
      ...netLines,
      ...taxLines,
    ];

    const result = this.tenant.ledger.post({
      actor: input.actor ?? null,
      entryDate: input.entryDate ?? voucherDate.iso,
      voucherId: voucher.id.value,
      text: input.text ?? '',
      lines,
    });

    return {
      entry: JSON.parse(JSON.stringify(result.entry)) as Record<string, unknown>,
      openItemsCreated: result.openItemsCreated.map((item) => JSON.parse(JSON.stringify(item)) as unknown),
      grossTotal: expansion.grossTotal,
      taxLines: expansion.taxLines,
      voucherId: voucher.id.value,
    };
  }
}
