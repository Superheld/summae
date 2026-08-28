import { DomainError } from '../domain-error.js';
import { AuditRecord, type AuditChanges } from '../records/audit-record.js';
import type {
  AccountRepository,
  AuditTrail,
  OpenItemRepository,
  PartnerRepository,
  VoucherRepository,
} from '../port.js';
import { AccountNumber } from '../substrate/account-number.js';
import type { Clock } from '../substrate/clock.js';
import { InvalidValue } from '../substrate/errors.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { parsePartnerKind } from '../substrate/types.js';
import { Uuid } from '../substrate/uuid.js';
import { Partner } from './partner.js';

function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/** Partner operations (api.md v0.4): createPartner / updatePartner with audit. */
export class PartnerService {
  constructor(
    private readonly partners: PartnerRepository,
    private readonly audit: AuditTrail,
    private readonly clock: Clock,
    private readonly ids: IdGenerator,
    /**
     * The chart, so an account link can be checked against it (F-CORE-032).
     *
     * Not optional: a service built without it would validate nothing and say nothing, which is the
     * state this check was added to end.
     */
    private readonly accounts: AccountRepository,
    /**
     * The two places a partner is reachable from the books (F-CORE-040) — needed only by `erase`,
     * which must refuse while either still names it.
     *
     * A journal entry never names a partner directly; it reaches one through its voucher, which is
     * why the journal is not consulted here and why an entry cannot orphan a reference this check
     * would miss.
     */
    private readonly vouchers: VoucherRepository,
    private readonly openItems: OpenItemRepository,
  ) {}

  /**
   * A partner may only be linked to accounts the books actually carry.
   *
   * Without this a partner could be linked to 9999 in a chart that stops at 3110: the operation
   * succeeded, the link was stored as a list of strings on the aggregate, and nothing ever reported
   * it. That is master data wrong for every reader of the books, not only for the screen that
   * entered it — the same argument that pulled `name` and `kind` in here rather than leaving them
   * to the embedding. The account link was made updatable in that pass and its check was not.
   *
   * Whole-list semantics are untouched: an empty list still clears the link.
   */
  private validateAccountNumbers(input: Record<string, unknown>): void {
    if (!Array.isArray(input.accountNumbers)) return;
    for (const value of input.accountNumbers) {
      if (typeof value !== 'string') continue;
      if (this.accounts.byNumber(AccountNumber.of(value)) === null) {
        throw new DomainError('E_ACCOUNT_UNKNOWN', `Account ${value} does not exist in this chart`, {
          account: value,
        });
      }
    }
  }

  /**
   * A partner needs a name, and the kinds are the three the manual names (F-CORE-032).
   *
   * Both used to be optional in the widest sense: `name` defaulted to `""`, so a request that
   * forgot it created a nameless partner indistinguishable from the next one and impossible to
   * pick out of a list; `kind` was a plain string, so `custommer` was a partner kind like any
   * other and only surfaced as a category nothing could filter on. An embedding application ended
   * up validating both itself, which is the wrong place: master data that is wrong here is wrong
   * for every reader of the books, not just for the screen that entered it.
   */
  private validateMasterData(input: Record<string, unknown>, nameRequired: boolean): void {
    const name = asString(input.name);
    if (nameRequired ? name === null || name.trim() === '' : 'name' in input && (name === null || name.trim() === '')) {
      throw new DomainError('E_INPUT_INVALID', 'createPartner: "name" must not be empty', {
        name: input.name === undefined ? null : input.name,
      });
    }
    if (input.kind !== undefined && input.kind !== null && parsePartnerKind(input.kind) === null) {
      throw new DomainError('E_INPUT_INVALID', 'partner "kind" must be "customer", "supplier" or "both"', {
        kind: input.kind,
      });
    }
  }

  create(input: Record<string, unknown>): Partner {
    this.validateMasterData(input, true);
    this.validateAccountNumbers(input);
    const accountNumbers = (Array.isArray(input.accountNumbers) ? input.accountNumbers : []).filter(
      (value): value is string => typeof value === 'string',
    );
    const address =
      input.address !== null && typeof input.address === 'object' && !Array.isArray(input.address)
        ? (input.address as Record<string, unknown>)
        : {};

    const name = asString(input.name) ?? '';
    const kind = asString(input.kind) ?? 'both';

    const partner = new Partner(
      this.ids.next(),
      name,
      kind,
      asString(input.vatId),
      typeof input.paymentTermsDays === 'number' ? input.paymentTermsDays : null,
      accountNumbers,
      address,
    );

    this.partners.add(partner);
    // A creation is a change from nothing, written as `from: null` rather than as an empty diff —
    // the idiom vouchers and fiscal years already used. The identifying fields only: the partner's
    // current state is retrievable from the master data, and a trail that copies the object is a
    // second source of truth rather than a history.
    this.recordAudit(input, 'created', partner.id, {
      name: { from: null, to: name },
      kind: { from: null, to: kind },
    });
    return partner;
  }

  update(input: Record<string, unknown>): Partner {
    const partner = this.require(input.partnerId);
    // Absent leaves the name alone; present and empty is the same mistake as creating without one.
    this.validateMasterData(input, false);
    this.validateAccountNumbers(input);
    const changes = partner.update(input);
    if (Object.keys(changes).length > 0) {
      this.partners.save(partner);
      this.recordAudit(input, 'updated', partner.id, changes);
    }
    return partner;
  }

  /**
   * Marks a partner as no longer in use, and takes it back (F-CORE-034).
   *
   * Both directions in one place, like the account lock, because the audit record is the point of
   * the operation and two copies of it would be two chances for one direction to record less.
   */
  setStatus(input: Record<string, unknown>, target: 'active' | 'inactive'): Partner {
    const partner = this.require(input.partnerId);
    const before = partner.status();
    if (target === 'inactive') partner.deactivate();
    else partner.reactivate();

    if (before !== partner.status()) {
      this.partners.save(partner);
      this.recordAudit(input, target === 'inactive' ? 'deactivated' : 'reactivated', partner.id, {
        status: { from: before, to: partner.status() },
      });
    }
    return partner;
  }

  /**
   * Erase a partner and the trail's records about it (F-CORE-040).
   *
   * **Why this exists next to `deactivate`, which reads like it should be enough.** It is not the
   * same question. `inactive` says *we no longer trade with them* and is a state the books keep;
   * erasure says *this record must not be here at all*. The mechanism is the same in every
   * jurisdiction and the reason is not: wherever a retention rule applies it applies to what the
   * books reference, and a partner the books have never referenced falls outside it. So the line
   * this operation draws — referenced or not — is the only line the core knows. Which rule puts a
   * record on which side of it, and under what name, is documented outside the core
   * (`docs/gdpr-conformance.md`) and never asserted here.
   *
   * **Why it also erases the audit records about the partner.** `createPartner` writes the name
   * and, if given, the address into `changes`. Removing the partner row while that record stands
   * erases nothing — the personal data simply moves to the place nobody looks. So the records
   * *about this partner* go with it, and a single new record is appended in their place naming the
   * id, the actor and the moment, and carrying **no personal payload**. The trail keeps the fact
   * that an erasure happened, which is what an audit asks of it, and stops keeping what the law
   * says must go.
   *
   * **What it will not touch.** A voucher or an open item naming the partner is a bookkeeping
   * record under retention, and the refusal is unconditional — `E_PARTNER_IN_USE`, with the counts,
   * so a caller can say *why* rather than only *no*. Nothing here can reach a journal entry.
   */
  erase(input: Record<string, unknown>): { id: string; erasedAuditRecords: number } {
    const partner = this.require(input.partnerId);

    const vouchers = this.vouchers.all().filter((voucher) => voucher.partnerId?.value === partner.id.value).length;
    const openItems = this.openItems.all().filter((item) => item.partnerId?.value === partner.id.value).length;

    if (vouchers > 0 || openItems > 0) {
      throw new DomainError(
        'E_PARTNER_IN_USE',
        `Business partner ${partner.id.value} is referenced by the books and is kept under the retention duty`,
        { partnerId: partner.id.value, vouchers, openItems },
      );
    }

    const erasedAuditRecords = this.audit.eraseFor('partner', partner.id);
    this.partners.remove(partner.id);
    // Appended after the erasure, never before: the record that documents it must not be one of
    // the records it removes.
    // `existed`, not an empty diff. The published invariant says every record carries before/after
    // values, and the contract test enforces it — which is the right pressure here rather than an
    // exception: what changed IS the existence of the record, and saying so costs nothing and
    // reveals nothing. A diff naming the erased fields would put the name back into the trail,
    // which is the one thing this operation exists to prevent.
    this.recordAudit(input, 'erased', partner.id, { existed: { from: true, to: false } });

    return { id: partner.id.value, erasedAuditRecords };
  }

  require(partnerId: unknown): Partner {
    let partner: Partner | null = null;
    if (typeof partnerId === 'string' && partnerId !== '') {
      try {
        partner = this.partners.byId(Uuid.fromString(partnerId));
      } catch (error) {
        if (!(error instanceof InvalidValue)) throw error;
      }
    }
    if (partner === null) {
      throw new DomainError('E_PARTNER_UNKNOWN', `Business partner ${typeof partnerId === 'string' ? partnerId : '?'} does not exist`);
    }
    return partner;
  }

  private recordAudit(
    input: Record<string, unknown>,
    action: string,
    objectId: Uuid,
    changes: AuditChanges,
  ): void {
    const actor = asString(input.actor);
    this.audit.append(
      new AuditRecord(
        this.ids.next(),
        this.clock.now().toISOString(),
        actor !== null && actor !== '' ? actor : 'system',
        'partner',
        objectId,
        action,
        changes,
      ),
    );
  }
}
