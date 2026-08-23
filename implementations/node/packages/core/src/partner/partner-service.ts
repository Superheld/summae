import { DomainError } from '../domain-error.js';
import { AuditRecord, type AuditChanges } from '../records/audit-record.js';
import type { AuditTrail, PartnerRepository } from '../port.js';
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
  ) {}

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
    const accountNumbers = (Array.isArray(input.accountNumbers) ? input.accountNumbers : []).filter(
      (value): value is string => typeof value === 'string',
    );
    const address =
      input.address !== null && typeof input.address === 'object' && !Array.isArray(input.address)
        ? (input.address as Record<string, unknown>)
        : {};

    const partner = new Partner(
      this.ids.next(),
      asString(input.name) ?? '',
      asString(input.kind) ?? 'both',
      asString(input.vatId),
      typeof input.paymentTermsDays === 'number' ? input.paymentTermsDays : null,
      accountNumbers,
      address,
    );

    this.partners.add(partner);
    this.recordAudit(input, 'created', partner.id, {});
    return partner;
  }

  update(input: Record<string, unknown>): Partner {
    const partner = this.require(input.partnerId);
    // Absent leaves the name alone; present and empty is the same mistake as creating without one.
    this.validateMasterData(input, false);
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
