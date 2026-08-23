import { DomainError } from '../domain-error.js';
import { CalendarDate } from '../substrate/calendar-date.js';
import { InvalidValue } from '../substrate/errors.js';
import type { Uuid } from '../substrate/uuid.js';
import { AuditWriter } from '../ledger/audit-writer.js';
import { Voucher } from '../records/voucher.js';
import type { Tenant } from './tenant.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * F-TAX-007: the supplier's taxation method on the voucher. It decides whether input tax may be
 * deducted on invoice or only on payment — a distinction German law makes binding for the
 * deducting side in 2028, and one only the incoming document can answer. The field was in the
 * data format (`enum ["accrual","cash"]`) and in both record classes from the start, but nothing
 * ever read it out of the input, so it could not be anything but null.
 *
 * An unknown value is rejected rather than dropped: silently storing null would look exactly like
 * "the supplier taxes on accrual", which is the answer that permits the earlier deduction.
 */
function supplierTaxationMethod(voucherData: Record<string, unknown>): string | null {
  const value = voucherData.supplierTaxationMethod;
  if (value === undefined || value === null) return null;

  if (value !== 'accrual' && value !== 'cash') {
    throw new DomainError(
      'E_INPUT_INVALID',
      `voucher.supplierTaxationMethod must be "accrual" or "cash", got ${typeof value === 'string' ? `"${value}"` : typeof value}`,
    );
  }

  return value;
}

/**
 * Application-layer composition `postVoucher` (api.md, part of the spec): SF-02/03
 * in one call — create voucher, expandTax, post, OP creation.
 */
export class PostVoucherService {
  constructor(private readonly tenant: Tenant) {}

  /** Build + store voucher from `input.voucher` — shared by postVoucher and createVoucher. */
  private buildAndAddVoucher(input: Record<string, unknown>): Voucher {
    const voucherData = isRecord(input.voucher) ? input.voucher : {};

    let voucherDate: CalendarDate;
    try {
      voucherDate = CalendarDate.of(asString(voucherData.voucherDate) ?? '');
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError('E_ENTRY_NO_VOUCHER', 'Voucher needs voucher.voucherDate');
      }
      throw error;
    }

    // v0.4: partner must exist before anything is created.
    let partnerId: Uuid | null = null;
    if (voucherData.partnerId !== undefined && voucherData.partnerId !== null) {
      partnerId = this.tenant.partnerService.require(voucherData.partnerId).id;
    }

    const date = (value: unknown): CalendarDate | null =>
      typeof value === 'string' ? CalendarDate.of(value) : null;
    const servicePeriod = isRecord(voucherData.servicePeriod) ? voucherData.servicePeriod : {};

    const voucher = new Voucher({
      id: this.tenant.ids.next(),
      voucherNumber: asString(voucherData.voucherNumber) ?? '',
      voucherDate,
      due: date(voucherData.due),
      recurring: voucherData.recurring === true,
      economicYear: typeof voucherData.economicYear === 'number' ? voucherData.economicYear : null,
      serviceDate: date(voucherData.serviceDate),
      servicePeriodFrom: date(servicePeriod.from),
      servicePeriodTo: date(servicePeriod.to),
      kind: asString(voucherData.kind),
      partnerId,
      issuer: asString(voucherData.issuer),
      supplierTaxationMethod: supplierTaxationMethod(voucherData),
    });
    this.tenant.vouchers.add(voucher);
    // Shared by createVoucher and postVoucher, so one record covers both. A voucher is the
    // anchor of the Belegfunktion — that one appeared has to be traceable even when no
    // posting follows it (createVoucher deliberately posts nothing).
    new AuditWriter(this.tenant.audit, this.tenant.clock, this.tenant.ids).record(
      typeof input.actor === 'string' && input.actor !== '' ? input.actor : 'system',
      'voucher',
      voucher.id,
      'created',
      {
        voucherNumber: { from: null, to: voucher.voucherNumber },
        voucherDate: { from: null, to: voucher.voucherDate.iso },
      },
    );

    return voucher;
  }

  /** createVoucher: create a voucher without posting — makes pack-mode fixtures complete (post/acquireAsset). */
  createVoucher(input: Record<string, unknown>): Record<string, unknown> {
    const voucher = this.buildAndAddVoucher(input);
    return { id: voucher.id.value, voucherNumber: voucher.voucherNumber };
  }

  post(input: Record<string, unknown>): Record<string, unknown> {
    const voucher = this.buildAndAddVoucher(input);
    const voucherDate = voucher.voucherDate;

    // Direct gross mode: explicit `lines` are posted without tax expansion
    // (e.g. payments). The voucher wraps an ordinary posting.
    if (Array.isArray(input.lines)) {
      const directResult = this.tenant.ledger.post({
        actor: input.actor ?? null,
        entryDate: input.entryDate ?? voucherDate.iso,
        voucherId: voucher.id.value,
        text: input.text ?? '',
        lines: input.lines,
      });
      return {
        entry: JSON.parse(JSON.stringify(directResult.entry)) as Record<string, unknown>,
        openItemsCreated: directResult.openItemsCreated.map((item) => JSON.parse(JSON.stringify(item)) as unknown),
        voucherId: voucher.id.value,
      };
    }

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
