import type { AssetRepository } from '../../port.js';
import { CalendarDate } from '../../substrate/calendar-date.js';

/**
 * Anlageverzeichnis (Pflicht auch bei EÜR, § 4 Abs. 3 S. 5 EStG). Sortierung:
 * Zugangsdatum, dann ID (deterministisch).
 */
export class AssetRegisterProjection {
  constructor(private readonly assets: AssetRepository) {}

  compute(params: Record<string, unknown>): { assets: Array<Record<string, unknown>> } {
    const asOf = typeof params.asOf === 'string' ? CalendarDate.of(params.asOf) : null;

    const sorted = [...this.assets.all()].sort((a, b) => {
      const byDate = a.acquiredOn.compareTo(b.acquiredOn);
      return byDate !== 0 ? byDate : a.id.compareTo(b.id);
    });

    const rows: Array<Record<string, unknown>> = [];
    for (const asset of sorted) {
      if (asOf !== null && asset.acquiredOn.isAfter(asOf)) continue;
      const row = asset.toJSON();
      row.accumulatedDepreciation = asset.accumulatedDepreciationAt(asOf).toJSON();
      row.bookValue = asset.bookValueAt(asOf).toJSON();
      if (asset.route === 'capitalize') row.depreciationSchedule = asset.scheduleSummary();
      rows.push(row);
    }
    return { assets: rows };
  }
}
