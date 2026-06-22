import type { JournalRepository, PartnerRepository, VoucherRepository } from '../port.js';
import { Money } from '../substrate/money.js';
import type { Currency } from '../substrate/currency.js';
import type { TaxCodeRegistry } from '../tax/tax-code-registry.js';

/**
 * ZM-Grundlage (v0.4, SF-21): innergemeinschaftliche Umsätze je USt-IdNr. und
 * Zeitraum — aus Kennzahl-Tags der igL-Schlüssel, Partner über den Beleg.
 */
export class EcSalesListProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly journal: JournalRepository,
    private readonly vouchers: VoucherRepository,
    private readonly partners: PartnerRepository,
    private readonly registry: TaxCodeRegistry,
  ) {}

  compute(params: Record<string, unknown>): { rows: Array<Record<string, string>> } {
    const year = typeof params.year === 'number' ? params.year : 0;
    const quarter = typeof params.quarter === 'number' ? params.quarter : 0;

    const intraCommunityKeys = new Set<string>();
    for (const version of this.registry.allVersions()) {
      if (version.mechanism === 'intra_community_supply' && version.reportingKey !== null) {
        intraCommunityKeys.add(version.reportingKey);
      }
    }

    const byVatId = new Map<string, Money>();

    for (const entry of this.journal.all()) {
      const voucher = this.vouchers.byId(entry.voucherId);
      const taxDate = voucher === null ? entry.entryDate : voucher.taxDate();
      if (taxDate.year() !== year) continue;
      if (quarter !== 0 && Math.floor((taxDate.month() - 1) / 3) + 1 !== quarter) continue;

      const partner = voucher?.partnerId == null ? null : this.partners.byId(voucher.partnerId);
      const vatId = partner?.vatId() ?? null;
      if (vatId === null) continue;

      for (const line of entry.lines()) {
        const rawKey = line.taxTag?.reportingKey;
        if (typeof rawKey !== 'string' && typeof rawKey !== 'number') continue;
        if (!intraCommunityKeys.has(String(rawKey))) continue;
        const signed = line.side === 'credit' ? line.money : line.money.negate();
        byVatId.set(vatId, (byVatId.get(vatId) ?? Money.zero(this.baseCurrency)).add(signed));
      }
    }

    const vatIds = [...byVatId.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const rows: Array<Record<string, string>> = [];
    for (const vatId of vatIds) {
      const amount = byVatId.get(vatId)!;
      if (amount.isZero()) continue;
      rows.push({ vatId, amount: amount.amountAsString(), kind: 'supply' });
    }
    return { rows };
  }
}
