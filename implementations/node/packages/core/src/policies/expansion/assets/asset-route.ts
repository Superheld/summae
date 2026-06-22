/** Low-value-asset switch (SF-05): three routes; the thresholds are rule-module data. */
export type AssetRoute = 'capitalize' | 'immediate_expense' | 'pool';

export function parseAssetRoute(value: unknown): AssetRoute | null {
  return value === 'capitalize' || value === 'immediate_expense' || value === 'pool' ? value : null;
}
