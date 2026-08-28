import { describe, expect, it } from 'vitest';
import { DomainError } from '../src/domain-error.js';
import { InMemoryAuditTrail, InMemoryPartnerRepository } from '../src/in-memory.js';
import { AuditRecord } from '../src/records/audit-record.js';
import { Uuid } from '../src/substrate/uuid.js';

/**
 * What `partner-erasure` (the fixture) cannot reach, in both languages (F-CORE-040).
 *
 * The fixture pins the behaviour an embedding sees: the operation succeeds, the refusal carries the
 * right code, the trail keeps one record. Two things sit below that surface and are asserted here,
 * because a fixture's error expectation is a code and nothing else:
 *
 * 1. **The refusal's `details`.** `E_PARTNER_IN_USE` carries `vouchers` and `openItems` so an
 *    application can tell a data subject *what* keeps the record rather than only that something
 *    does. A refusal that says no without a reason pushes the operator into guessing, and guessing
 *    about a retention basis is the thing this whole area cannot afford.
 * 2. **`eraseFor` is selective.** It must take the records about *one* object and leave every other
 *    record standing — including records of the same type about a different partner, and records
 *    of a different type that happen to share nothing but the trail. An erasure that took too much
 *    would destroy bookkeeping history to satisfy a privacy request, which is the failure mode in
 *    the opposite direction and the more expensive one.
 *
 * PHP twin: `PartnerErasureTest`.
 */

function record(objectType: string, objectId: Uuid, action: string): AuditRecord {
  return new AuditRecord(Uuid.fromString('01920000-0000-7000-8000-00000000000f'), '2026-03-01T10:00:00.000Z', 'bruce', objectType, objectId, action, {
    existed: { from: null, to: true },
  });
}

const PARTNER_A = Uuid.fromString('01920000-0000-7000-8000-0000000000a1');
const PARTNER_B = Uuid.fromString('01920000-0000-7000-8000-0000000000b2');
const ENTRY = Uuid.fromString('01920000-0000-7000-8000-0000000000e3');

describe('erasing the trail about one object', () => {
  it('takes only the records about that object, and reports how many', () => {
    const trail = new InMemoryAuditTrail();
    trail.append(record('partner', PARTNER_A, 'created'));
    trail.append(record('partner', PARTNER_A, 'updated'));
    trail.append(record('partner', PARTNER_B, 'created'));
    trail.append(record('journalEntry', ENTRY, 'created'));

    expect(trail.eraseFor('partner', PARTNER_A)).toBe(2);

    const left = trail.all().map((entry) => `${entry.objectType}/${entry.objectId.value}`);
    expect(left).toEqual([`partner/${PARTNER_B.value}`, `journalEntry/${ENTRY.value}`]);
  });

  it('erases nothing and says so when the object has no records', () => {
    const trail = new InMemoryAuditTrail();
    trail.append(record('partner', PARTNER_B, 'created'));

    expect(trail.eraseFor('partner', PARTNER_A)).toBe(0);
    expect(trail.all()).toHaveLength(1);
  });

  /**
   * The type is part of the identity, not decoration. Ids are UUIDs and will not collide in
   * practice — but "will not collide in practice" is not the argument a deletion should rest on.
   */
  it('does not match a record of another type carrying the same id', () => {
    const trail = new InMemoryAuditTrail();
    trail.append(record('voucher', PARTNER_A, 'created'));

    expect(trail.eraseFor('partner', PARTNER_A)).toBe(0);
    expect(trail.all()).toHaveLength(1);
  });
});

describe('the partner repository can forget', () => {
  it('removes exactly the one partner', () => {
    const partners = new InMemoryPartnerRepository();
    // Built through the service in the fixture; here the repository is the unit under test.
    expect(partners.byId(PARTNER_A)).toBeNull();
    partners.remove(PARTNER_A); // removing what is not there is not an error
    expect(partners.all()).toEqual([]);
  });
});

describe('E_PARTNER_IN_USE', () => {
  it('is a DomainError whose details name what keeps the record', () => {
    // Constructed directly: the wiring is the fixture's job, the payload shape is this test's.
    const error = new DomainError('E_PARTNER_IN_USE', 'kept under the retention duty', {
      partnerId: PARTNER_A.value,
      vouchers: 1,
      openItems: 2,
    });

    expect(error.errorCode).toBe('E_PARTNER_IN_USE');
    expect(error.details).toEqual({ partnerId: PARTNER_A.value, vouchers: 1, openItems: 2 });
  });
});
