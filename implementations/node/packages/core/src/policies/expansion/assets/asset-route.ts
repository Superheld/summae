/** GWG-Weiche (SF-05): drei Pfade; die Grenzen sind Regelmodul-Daten. */
export type AssetRoute = 'capitalize' | 'immediate_expense' | 'pool';

export function parseAssetRoute(value: unknown): AssetRoute | null {
  return value === 'capitalize' || value === 'immediate_expense' || value === 'pool' ? value : null;
}
