import type { AuditChanges } from '../ledger/audit-record.js';
import type { Uuid } from '../substrate/uuid.js';

/**
 * Geschäftspartner (datenformat.md v0.4) — bewusst schlank: OP-je-Partner,
 * igL-Nachweis (USt-IdNr.), ZM-Grundlage, DATEV-Stammdaten.
 */
export class Partner {
  private partnerName: string;
  private partnerKind: string;
  private partnerVatId: string | null;
  private partnerPaymentTermsDays: number | null;

  constructor(
    readonly id: Uuid,
    name: string,
    kind: string,
    vatId: string | null,
    paymentTermsDays: number | null,
    private readonly accountNumbers: string[] = [],
    private readonly address: Record<string, unknown> = {},
  ) {
    this.partnerName = name;
    this.partnerKind = kind;
    this.partnerVatId = vatId;
    this.partnerPaymentTermsDays = paymentTermsDays;
  }

  name(): string {
    return this.partnerName;
  }

  vatId(): string | null {
    return this.partnerVatId;
  }

  update(input: Record<string, unknown>): AuditChanges {
    const changes: AuditChanges = {};

    if (typeof input.name === 'string' && input.name !== this.partnerName) {
      changes.name = { from: this.partnerName, to: input.name };
      this.partnerName = input.name;
    }
    if (
      'vatId' in input &&
      input.vatId !== this.partnerVatId &&
      (typeof input.vatId === 'string' || input.vatId === null)
    ) {
      changes.vatId = { from: this.partnerVatId, to: input.vatId };
      this.partnerVatId = input.vatId;
    }
    if (typeof input.kind === 'string' && input.kind !== this.partnerKind) {
      changes.kind = { from: this.partnerKind, to: input.kind };
      this.partnerKind = input.kind;
    }
    if (
      typeof input.paymentTermsDays === 'number' &&
      input.paymentTermsDays !== this.partnerPaymentTermsDays
    ) {
      changes.paymentTermsDays = { from: this.partnerPaymentTermsDays, to: input.paymentTermsDays };
      this.partnerPaymentTermsDays = input.paymentTermsDays;
    }

    return changes;
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      name: this.partnerName,
      kind: this.partnerKind,
      vatId: this.partnerVatId,
      paymentTermsDays: this.partnerPaymentTermsDays,
      accountNumbers: this.accountNumbers,
      address: Object.keys(this.address).length === 0 ? null : this.address,
    };
  }
}
