import type { JournalRepository, PartnerRepository, VoucherRepository } from '../../port.js';
import { Money } from '../../substrate/money.js';
import type { Currency } from '../../substrate/currency.js';
import type { TaxCodeRegistry } from '../expansion/tax/tax-code-registry.js';
import { mechanismFor } from '../expansion/tax/tax-mechanisms.js';
import { integerOr } from './parameters.js';

/**
 * EC sales list basis (v0.4, SF-21): intra-community supplies per VAT ID and
 * period — from reporting-key tags of the intra-community-supply codes, partner via the voucher.
 *
 * **A supply that cannot be reported is reported as unreportable** (F-IO-011). The list is keyed by
 * VAT ID, so a supply whose partner has none used to fall out of it silently: two postings, one
 * with a VAT ID and one without, and the answer was one row and nothing else. That is the dangerous
 * direction — in the jurisdictions that have this report, a supply without the recipient's
 * registration number is typically not exempt at all, so what
 * dropped out was exactly the case where something is wrong. Same shape as `vatReturn.gapWarnings`,
 * and for the same reason: the warning belongs at the figures, next to what is filed, rather than
 * in a projection of its own that whoever files may never open.
 *
 * Not a refusal. Whether a missing VAT ID makes a supply taxable is jurisdiction law and the
 * embedding's call; the library's job is to make sure the case is never invisible.
 */
export class EcSalesListProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly journal: JournalRepository,
    private readonly vouchers: VoucherRepository,
    private readonly partners: PartnerRepository,
    private readonly registry: TaxCodeRegistry,
  ) {}

  compute(params: Record<string, unknown>): {
    rows: Array<Record<string, string>>;
    gapWarnings: Array<Record<string, unknown>>;
  } {
    const year = integerOr(params.year, 0);
    const quarter = integerOr(params.quarter, 0);

    const intraCommunityKeys = new Set<string>();
    for (const version of this.registry.allVersions()) {
      if (mechanismFor(version.mechanism).affectsEcSalesList && version.reportingKey !== null) {
        intraCommunityKeys.add(version.reportingKey);
      }
    }

    const byVatId = new Map<string, Money>();
    const gapWarnings: Array<Record<string, unknown>> = [];

    for (const entry of this.journal.all()) {
      const voucher = this.vouchers.byId(entry.voucherId);
      const taxDate = voucher === null ? entry.entryDate : voucher.taxDate();
      if (taxDate.year() !== year) continue;
      if (quarter !== 0 && Math.floor((taxDate.month() - 1) / 3) + 1 !== quarter) continue;

      const partner = voucher?.partnerId == null ? null : this.partners.byId(voucher.partnerId);
      const vatId = partner?.vatId() ?? null;

      for (const line of entry.lines()) {
        const rawKey = line.taxTag?.reportingKey;
        if (typeof rawKey !== 'string' && typeof rawKey !== 'number') continue;
        if (!intraCommunityKeys.has(String(rawKey))) continue;

        // The line IS an intra-community supply — decided before the partner is looked at, which is
        // the whole change. Deciding it afterwards is what made a missing VAT ID look like a
        // posting that was never an intra-community supply to begin with.
        if (vatId === null) {
          gapWarnings.push({
            reason: partner === null ? 'supply_without_partner' : 'partner_without_vat_id',
            sequenceNumber: entry.sequenceNumber,
            entryDate: entry.entryDate.iso,
            reportingKey: String(rawKey),
            money: line.money.toJSON(),
            partnerId: partner?.id.value ?? null,
          });
          continue;
        }

        const signed = line.side === 'credit' ? line.money : line.money.negate();
        byVatId.set(vatId, (byVatId.get(vatId) ?? Money.zero(this.baseCurrency)).add(signed));
      }
    }

    // Journal order: the order the postings happened in is the order somebody checking them will
    // work through. Same rule as vatReturn's warnings.
    gapWarnings.sort((a, b) => Number(a.sequenceNumber) - Number(b.sequenceNumber));

    const vatIds = [...byVatId.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const rows: Array<Record<string, string>> = [];
    for (const vatId of vatIds) {
      const amount = byVatId.get(vatId)!;
      if (amount.isZero()) continue;
      rows.push({ vatId, amount: amount.amountAsString(), kind: 'supply' });
    }
    return { rows, gapWarnings };
  }
}
