import type {
  AssetRepository,
  AuditTrail,
  DeferralRepository,
  PartnerRepository,
  ProvisionRepository,
  VoucherRepository,
} from '../../port.js';
import { FORMAT_VERSION } from '../../substrate/format-version.js';

/**
 * Where identifying data can sit in these books, and how much of it is actually there (F-CORE-041).
 *
 * The counterpart to `systemDescription`, which answers the same shape of question about auditing:
 * *what does this system record, and about what*. An operator preparing a record of processing
 * activities needs the same answer about people, and until now had to reconstruct it by reading
 * the schema by hand — which is a list that goes stale silently, because a field that has been
 * renamed still reads like a field.
 *
 * **Two halves, split along the project's own axis, and the split is the reason this belongs in
 * summae rather than in every application separately.**
 *
 * - **Where identifying data can sit is mechanism.** The partner aggregate has a name; the audit
 *   trail records an actor; a posting carries free text. That is true of the `us` pack exactly as
 *   of the `de` pack, it does not cite a statute, and it is what this projection reports.
 * - **Whether a given field *counts* as personal data is not mechanism.** The GDPR and the CCPA
 *   answer that differently, and a company identifier is personal data for a sole trader and not
 *   for a corporation. So this projection **never says "this is personal data"** — it says *this
 *   field holds free text an operator supplies*, and it counts what is present. The classification
 *   is the operator's, with their own legal advice, and `docs/gdpr-conformance.md` §1 is summae's
 *   own reading of it for the German/EU case.
 *
 * **It reports shape and counts, never content.** `filled` says how many partners carry an address,
 * not what any address says; `addressKeys` says which keys occur, not their values. A projection
 * built to help with a privacy obligation must not itself become the convenient place to read
 * everybody's data out of — and an operator asking "what do we hold" needs the inventory, not the
 * records, which `journalExport` already gives them.
 */
export class PersonalDataDescriptionProjection {
  constructor(
    private readonly partners: PartnerRepository,
    private readonly vouchers: VoucherRepository,
    private readonly audit: AuditTrail,
    // The three stores that hold operator free text OUTSIDE the exchange format (IMPL-045).
    // Optional because a hand-built tenant may not carry them; an absent store reports
    // `present: null` — "not counted" — never 0, which would claim there is nothing there.
    private readonly assets?: AssetRepository,
    private readonly provisions?: ProvisionRepository,
    private readonly deferrals?: DeferralRepository,
  ) {}

  compute(_params: Record<string, unknown>): Record<string, unknown> {
    const partners = this.partners.all();
    const vouchers = this.vouchers.all();

    const addressKeys = new Set<string>();
    let withAddress = 0;
    let withVatId = 0;
    for (const partner of partners) {
      const address = partner.toJSON().address;
      if (address !== null && typeof address === 'object' && !Array.isArray(address)) {
        const keys = Object.keys(address as Record<string, unknown>);
        if (keys.length > 0) {
          withAddress += 1;
          for (const key of keys) addressKeys.add(key);
        }
      }
      if (typeof partner.toJSON().vatId === 'string') withVatId += 1;
    }

    const actors = new Set<string>();
    for (const record of this.audit.all()) actors.add(record.actor);

    let issuers = 0;
    for (const voucher of vouchers) {
      if (typeof voucher.toJSON().issuer === 'string') issuers += 1;
    }

    return {
      formatVersion: FORMAT_VERSION,
      // The declared shape: every place a value an operator typed can come to rest. `freeText`
      // marks the ones whose content summae neither constrains nor interprets — the fields where
      // anything at all can end up, which is what an inventory most needs flagged.
      fields: [
        { holder: 'partner', field: 'name', freeText: true, required: true, present: partners.length },
        { holder: 'partner', field: 'vatId', freeText: true, required: false, present: withVatId },
        { holder: 'partner', field: 'address', freeText: true, required: false, present: withAddress },
        { holder: 'voucher', field: 'issuer', freeText: true, required: false, present: issuers },
        { holder: 'journalEntry', field: 'text', freeText: true, required: false, present: null },
        { holder: 'auditRecord', field: 'actor', freeText: true, required: true, present: actors.size },
        {
          // The one field that mirrors another: a diff of a partner change carries whatever the
          // partner held. An inventory that lists `partner.name` and stops has missed the copy.
          holder: 'auditRecord',
          field: 'changes',
          freeText: true,
          required: true,
          present: null,
          mirrors: 'the fields of whatever record changed',
        },
        // Three fields that are NOT in the exchange format and were missing from this inventory
        // until 2026-08-29 (IMPL-045). `journalExport` does not carry them, so an Art. 30 record
        // assembled from the export alone misses them — and a provision is by its nature often
        // about a named party: a dispute, a warranty claim, a severance.
        { holder: 'asset', field: 'name', freeText: true, required: true, present: this.assets?.all().length ?? null },
        { holder: 'provision', field: 'reason', freeText: true, required: true, present: this.provisions?.all().length ?? null },
        { holder: 'deferral', field: 'reason', freeText: true, required: true, present: this.deferrals?.all().length ?? null },
      ],
      // Which address keys this tenant's data actually uses. The format declares a recommended
      // shape and does not forbid others, so the only truthful answer to "what is in there" is to
      // look — which is exactly the question a hand-written inventory cannot answer.
      addressKeys: [...addressKeys].sort(),
      counts: {
        partners: partners.length,
        vouchers: vouchers.length,
        distinctActors: actors.size,
      },
      // Stated rather than implied: an operator reading this must not conclude that summae has
      // classified anything for them.
      classification: 'none — summae reports where operator-supplied text can sit, not which of it is personal data under any jurisdiction',
    };
  }
}
