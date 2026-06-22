import type { CalendarDate } from '../substrate/calendar-date.js';
import type { Uuid } from '../substrate/uuid.js';

/**
 * Voucher (ledger-modell.md aggregate 4): exists before/without a posting, several
 * postings can reference it. Metadata for EÜR/VAT (due, recurring,
 * economicYear, serviceDate/servicePeriod, partnerId, kind …).
 */
export interface VoucherProps {
  readonly id: Uuid;
  readonly voucherNumber: string;
  readonly voucherDate: CalendarDate;
  readonly due?: CalendarDate | null;
  readonly recurring?: boolean;
  readonly economicYear?: number | null;
  readonly supplierTaxationMethod?: string | null;
  readonly serviceDate?: CalendarDate | null;
  readonly servicePeriodFrom?: CalendarDate | null;
  readonly servicePeriodTo?: CalendarDate | null;
  readonly kind?: string | null;
  readonly partnerId?: Uuid | null;
  readonly issuer?: string | null;
}

export class Voucher {
  readonly id: Uuid;
  readonly voucherNumber: string;
  readonly voucherDate: CalendarDate;
  readonly due: CalendarDate | null;
  readonly recurring: boolean;
  readonly economicYear: number | null;
  readonly supplierTaxationMethod: string | null;
  readonly serviceDate: CalendarDate | null;
  readonly servicePeriodFrom: CalendarDate | null;
  readonly servicePeriodTo: CalendarDate | null;
  readonly kind: string | null;
  readonly partnerId: Uuid | null;
  readonly issuer: string | null;

  constructor(props: VoucherProps) {
    this.id = props.id;
    this.voucherNumber = props.voucherNumber;
    this.voucherDate = props.voucherDate;
    this.due = props.due ?? null;
    this.recurring = props.recurring ?? false;
    this.economicYear = props.economicYear ?? null;
    this.supplierTaxationMethod = props.supplierTaxationMethod ?? null;
    this.serviceDate = props.serviceDate ?? null;
    this.servicePeriodFrom = props.servicePeriodFrom ?? null;
    this.servicePeriodTo = props.servicePeriodTo ?? null;
    this.kind = props.kind ?? null;
    this.partnerId = props.partnerId ?? null;
    this.issuer = props.issuer ?? null;
  }

  /** Tax-relevant date: service date, fallback voucher date. */
  taxDate(): CalendarDate {
    return this.serviceDate ?? this.servicePeriodTo ?? this.voucherDate;
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      voucherNumber: this.voucherNumber,
      voucherDate: this.voucherDate.iso,
      due: this.due?.iso ?? null,
      recurring: this.recurring,
      economicYear: this.economicYear,
      supplierTaxationMethod: this.supplierTaxationMethod,
      serviceDate: this.serviceDate?.iso ?? null,
      servicePeriod:
        this.servicePeriodFrom === null
          ? null
          : { from: this.servicePeriodFrom.iso, to: this.servicePeriodTo?.iso ?? null },
      kind: this.kind,
      partnerId: this.partnerId?.value ?? null,
      issuer: this.issuer,
    };
  }
}
