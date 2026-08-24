import type { AssetRepository } from '../../port.js';
import { CalendarDate } from '../../substrate/calendar-date.js';

/**
 * Asset register (a jurisdiction may mandate it even under cash-basis accounting). Sorting:
 * acquisition date, then ID (deterministic).
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
      // The additional allowance, from the register rather than only from a booking's answer
      // (F-AST-005). `bookSpecialDepreciation` reports what is left AFTER it ran; before it ran,
      // nothing said whether the asset had elected the allowance at all — so a screen had to offer
      // the form on every capitalised row and let the engine refuse the ones that had not.
      const remaining = asset.specialDepreciationRemaining();
      row.specialDepreciation = {
        elected: asset.specialDepreciationBudget !== null,
        allowance: asset.specialDepreciationBudget?.toJSON() ?? null,
        remaining: remaining?.toJSON() ?? null,
      };
      rows.push(row);
    }
    return { assets: rows };
  }
}
