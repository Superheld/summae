import { canonicalJson } from '../substrate/canonical-json.js';
import type { AuditChanges } from '../records/audit-record.js';
import type { Uuid } from '../substrate/uuid.js';

/**
 * Business partner (datenformat.md v0.4) — deliberately lean: OP-per-partner,
 * intra-community supply proof (VAT ID), EC sales list basis, DATEV master data.
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
    private accountNumbers: string[] = [],
    private address: Record<string, unknown> = {},
    /**
     * Whether the partner is still in use (F-CORE-034).
     *
     * There is no `deletePartner` and there should not be: the books keep what they referenced,
     * and an id that open items point at must not vanish. But a partner nobody trades with any
     * more has to be sayable somewhere, or every application invents its own list beside the
     * ledger — which is the state that made this a finding.
     *
     * It is a **state, not a control**. An inactive partner refuses nothing: old items still
     * settle, old vouchers still read, and a posting that names one is not rejected. Whether a
     * picker still offers it is the application's workflow, and summae's own scope rule puts
     * "the user must…" on that side. Compare `Account.lock`, which does have teeth — the
     * difference is deliberate and the two words are different on purpose.
     */
    private partnerStatus: 'active' | 'inactive' = 'active',
  ) {
    this.partnerName = name;
    this.partnerKind = kind;
    this.partnerVatId = vatId;
    this.partnerPaymentTermsDays = paymentTermsDays;
  }

  name(): string {
    return this.partnerName;
  }

  status(): 'active' | 'inactive' {
    return this.partnerStatus;
  }

  isActive(): boolean {
    return this.partnerStatus === 'active';
  }

  deactivate(): void {
    this.partnerStatus = 'inactive';
  }

  reactivate(): void {
    this.partnerStatus = 'active';
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
    // `null` clears the term, the way `vatId: null` above already did. Reading it with a
    // `typeof === 'number'` check meant an agreed payment term could be set and never taken back:
    // neither null nor an absent field removed it, and the two fields behaved differently with
    // nothing saying so.
    if (
      'paymentTermsDays' in input &&
      (typeof input.paymentTermsDays === 'number' || input.paymentTermsDays === null) &&
      input.paymentTermsDays !== this.partnerPaymentTermsDays
    ) {
      changes.paymentTermsDays = { from: this.partnerPaymentTermsDays, to: input.paymentTermsDays };
      this.partnerPaymentTermsDays = input.paymentTermsDays;
    }
    // Create-only until now, which made a wrong account link permanent: the partner had to be
    // abandoned and created again under a new id, and every open item stayed on the old one. Both
    // replace wholesale rather than merging — "these are the accounts now" is a statement an
    // application can make from a form, while a merge would need a way to say "remove this one".
    if (Array.isArray(input.accountNumbers)) {
      const next = input.accountNumbers.filter((value): value is string => typeof value === 'string');
      if (canonicalJson(next) !== canonicalJson(this.accountNumbers)) {
        changes.accountNumbers = { from: [...this.accountNumbers], to: next };
        this.accountNumbers = next;
      }
    }
    if (input.address !== undefined && input.address !== null && typeof input.address === 'object') {
      const next = Array.isArray(input.address) ? {} : (input.address as Record<string, unknown>);
      if (canonicalJson(next) !== canonicalJson(this.address)) {
        changes.address = { from: { ...this.address }, to: next };
        this.address = next;
      }
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
      status: this.partnerStatus,
    };
  }
}
