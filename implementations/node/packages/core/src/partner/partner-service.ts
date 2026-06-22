import { DomainError } from '../domain-error.js';
import { AuditRecord, type AuditChanges } from '../ledger/audit-record.js';
import type { AuditTrail, PartnerRepository } from '../port.js';
import type { Clock } from '../substrate/clock.js';
import { InvalidValue } from '../substrate/errors.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { Uuid } from '../substrate/uuid.js';
import { Partner } from './partner.js';

function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/** Partner-Operationen (api.md v0.4): createPartner / updatePartner mit Audit. */
export class PartnerService {
  constructor(
    private readonly partners: PartnerRepository,
    private readonly audit: AuditTrail,
    private readonly clock: Clock,
    private readonly ids: IdGenerator,
  ) {}

  create(input: Record<string, unknown>): Partner {
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
    const changes = partner.update(input);
    if (Object.keys(changes).length > 0) {
      this.partners.save(partner);
      this.recordAudit(input, 'updated', partner.id, changes);
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
      throw new DomainError('E_PARTNER_UNKNOWN', `Geschäftspartner ${typeof partnerId === 'string' ? partnerId : '?'} existiert nicht`);
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
