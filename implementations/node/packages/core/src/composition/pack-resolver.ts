import { DomainError } from '../domain-error.js';

/**
 * Pack-Resolver (`resolvePack`) — reine, seiteneffektfreie Auflösung eines
 * Manifests gegen einen Modulbestand zu einem `ResolvedPack`. Design:
 * `_bauflow-pack-gate01/design/module-manifest-resolver.md` (§ 3 Resolver-Semantik,
 * § 4 ResolvedPack == ruleModules-Bündel der TenantFactory).
 *
 * Leitprinzip: keine neue Engine-Fähigkeit. Der Resolver erfindet nichts; er wählt,
 * prüft und faltet vorhandene Modul-Daten zu genau der Struktur, die `TenantFactory`
 * heute hand-gereicht konsumiert. Scheitert **laut** (genau ein `E_PACK_*`/`E_POLICY_*`)
 * statt still falsch zu rechnen.
 *
 * Fehlerklassen (§ 3.2): `E_PACK_UNRESOLVED_REF` = eine Referenz zeigt ins Nichts;
 * `E_PACK_INCOHERENT` = Referenzen existieren, aber das Bündel ist widersprüchlich;
 * `E_POLICY_INVALID` = Policy-Wert/-Kopie falsch. Referenz-Existenz (Schritt 2/3) hat
 * Vorrang vor Kohärenz/Integrität (4/5).
 */

const MODULE_KINDS = ['accounts', 'tax', 'mapping', 'depreciation', 'policy', 'assetAccounts'] as const;
const ASSET_ACCOUNT_KEYS = [
  'acquisitionCounterAccount',
  'depreciationExpenseAccount',
  'gwgExpenseAccount',
  'disposalProceedsAccount',
  'disposalLossAccount',
] as const;
const ROUNDING_MODES = ['halfUpAwayFromZero', 'halfEven'];
const TAX_GRANULARITIES = ['perVoucher', 'perLine'];

export interface ModuleRef {
  kind: string;
  id: string;
  version?: string;
}

export interface PackModule {
  formatVersion?: string;
  id: string;
  kind: string;
  version: string;
  name?: string;
  contributes?: string[];
  dependsOn?: ModuleRef[];
  data: Record<string, unknown>;
}

export interface PackManifest {
  formatVersion?: string;
  id: string;
  name?: string;
  version: string;
  modules: ModuleRef[];
  overrides?: { op: string; ref: ModuleRef; with?: ModuleRef }[];
  taxCodes?: string[];
  defaults?: Record<string, unknown>;
  packPolicy: Record<string, unknown>;
}

export interface ResolvedPack {
  id: string;
  version: string;
  chartOfAccounts: { accounts: Record<string, unknown>[] };
  taxCodes: Record<string, unknown>[];
  mappings: Record<string, unknown>[];
  assetAccounts: Record<string, unknown> | null;
  depreciation: Record<string, unknown> | null;
  packPolicy: Record<string, unknown>;
  profile: Record<string, unknown>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}
function recordList(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? value.filter(isRecord) : [];
}
function refKey(kind: string, id: string): string {
  return `${kind}|${id}`;
}
/** Stabiler Vergleich nach Unicode-Codepoints (Determinismus-Sortierregel). */
function byCodepoint(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/**
 * Löst ein Manifest gegen einen Modulbestand auf. Wirft `DomainError` mit dem
 * passenden `E_PACK_*`/`E_POLICY_*`-Code; liefert sonst einen vollständig integren
 * `ResolvedPack`.
 */
export function resolvePack(manifest: PackManifest, moduleSource: PackModule[]): ResolvedPack {
  // 1. Effektive Modulliste: overrides (remove/replace) in Array-Reihenfolge anwenden.
  const effective: ModuleRef[] = manifest.modules.map((m) => ({ ...m }));
  for (const ov of manifest.overrides ?? []) {
    const idx = effective.findIndex((m) => m.kind === ov.ref.kind && m.id === ov.ref.id);
    if (idx < 0) {
      // Override greift ins Leere (auch: zweites remove/replace auf dieselbe ref).
      throw new DomainError('E_PACK_INCOHERENT', `Override greift nicht: ${refKey(ov.ref.kind, ov.ref.id)}`);
    }
    if (ov.op === 'remove') {
      effective.splice(idx, 1);
    } else if (ov.op === 'replace') {
      if (!ov.with) throw new DomainError('E_PACK_INCOHERENT', 'replace-Override ohne "with"');
      effective[idx] = { ...ov.with };
    } else {
      throw new DomainError('E_PACK_INCOHERENT', `Unbekannte Override-Operation: ${ov.op}`);
    }
  }

  // 2. Modul-Referenzen gegen den Bestand auflösen (version fehlt → höchste per Codepoint).
  const resolved: PackModule[] = [];
  for (const ref of effective) {
    const candidates = moduleSource.filter(
      (m) => m.kind === ref.kind && m.id === ref.id && (ref.version === undefined || m.version === ref.version),
    );
    if (candidates.length === 0) {
      throw new DomainError('E_PACK_UNRESOLVED_REF', `Modul nicht gefunden: ${refKey(ref.kind, ref.id)}`);
    }
    candidates.sort((a, b) => byCodepoint(a.version, b.version));
    resolved.push(candidates[candidates.length - 1]!);
  }

  // unbekanntes kind → INCOHERENT
  for (const m of resolved) {
    if (!MODULE_KINDS.includes(m.kind as (typeof MODULE_KINDS)[number])) {
      throw new DomainError('E_PACK_INCOHERENT', `Unbekanntes Modul-kind: ${m.kind}`);
    }
  }

  // 3. Abhängigkeits-DAG: fehlende dependsOn-Referenz → UNRESOLVED_REF (vor Zyklus-Check).
  const present = new Set(resolved.map((m) => refKey(m.kind, m.id)));
  for (const m of resolved) {
    for (const dep of m.dependsOn ?? []) {
      if (!present.has(refKey(dep.kind, dep.id))) {
        throw new DomainError(
          'E_PACK_UNRESOLVED_REF',
          `dependsOn zeigt auf nicht gelistetes Modul: ${refKey(dep.kind, dep.id)}`,
        );
      }
    }
  }
  const sorted = topoSort(resolved, present);

  // 4. Falten (topologisch). Kollidierende Beiträge → INCOHERENT (kein stilles Überschreiben).
  const accounts: Record<string, unknown>[] = [];
  const accountNumbers = new Set<string>();
  const taxCodes: Record<string, unknown>[] = [];
  const taxCodeCodes = new Set<string>();
  const mappings: Record<string, unknown>[] = [];
  const mappingIds = new Set<string>();
  let assetAccounts: Record<string, unknown> | null = null;
  let depreciation: Record<string, unknown> | null = null;
  let packPolicyModule: Record<string, unknown> | null = null;

  for (const m of sorted) {
    switch (m.kind) {
      case 'accounts':
        for (const account of recordList(m.data.accounts)) {
          const number = asString(account.number) ?? '';
          if (accountNumbers.has(number)) {
            throw new DomainError('E_PACK_INCOHERENT', `Konto-Nummer doppelt: ${number}`);
          }
          accountNumbers.add(number);
          accounts.push(account);
        }
        break;
      case 'tax':
        for (const taxCode of recordList(m.data.taxCodes)) {
          const code = asString(taxCode.code) ?? '';
          if (taxCodeCodes.has(code)) {
            throw new DomainError('E_PACK_INCOHERENT', `taxCode.code doppelt: ${code}`);
          }
          taxCodeCodes.add(code);
          taxCodes.push(taxCode);
        }
        break;
      case 'mapping': {
        const mapping = isRecord(m.data.mapping) ? m.data.mapping : null;
        if (mapping === null) break;
        const id = asString(mapping.id) ?? '';
        if (mappingIds.has(id)) {
          throw new DomainError('E_PACK_INCOHERENT', `mapping.id doppelt: ${id}`);
        }
        mappingIds.add(id);
        mappings.push(mapping);
        break;
      }
      case 'assetAccounts':
        assetAccounts = m.data;
        break;
      case 'depreciation':
        depreciation = m.data;
        break;
      case 'policy':
        if (packPolicyModule !== null) {
          throw new DomainError('E_PACK_INCOHERENT', 'Mehr als ein policy-Modul');
        }
        packPolicyModule = isRecord(m.data.packPolicy) ? m.data.packPolicy : {};
        break;
    }
  }

  // 5. Referentielle Integrität.
  // I1: taxAccount (+ inputTaxAccount bei reverse_charge) existiert im Kontenrahmen.
  for (const taxCode of taxCodes) {
    for (const version of recordList(taxCode.versions)) {
      const taxAccount = asString(version.taxAccount);
      if (taxAccount !== null && !accountNumbers.has(taxAccount)) {
        throw new DomainError('E_PACK_UNRESOLVED_REF', `taxAccount ohne Konto: ${taxAccount} (I1)`);
      }
      if (asString(version.mechanism) === 'reverse_charge') {
        const inputTaxAccount = asString(version.inputTaxAccount);
        if (inputTaxAccount !== null && !accountNumbers.has(inputTaxAccount)) {
          throw new DomainError('E_PACK_UNRESOLVED_REF', `inputTaxAccount ohne Konto: ${inputTaxAccount} (I1)`);
        }
      }
    }
  }
  // I3: alle fünf assetAccounts.*Account (+ perClass) existieren.
  if (assetAccounts !== null) {
    const def = isRecord(assetAccounts.default) ? assetAccounts.default : {};
    for (const key of ASSET_ACCOUNT_KEYS) {
      const number = asString(def[key]);
      if (number === null || !accountNumbers.has(number)) {
        throw new DomainError('E_PACK_UNRESOLVED_REF', `assetAccounts.${key} ohne Konto (I3)`);
      }
    }
    const perClass = isRecord(assetAccounts.perClass) ? assetAccounts.perClass : {};
    for (const cls of Object.values(perClass)) {
      if (!isRecord(cls)) continue;
      for (const value of Object.values(cls)) {
        const number = asString(value);
        if (number !== null && !accountNumbers.has(number)) {
          throw new DomainError('E_PACK_UNRESOLVED_REF', `assetAccounts.perClass ohne Konto: ${number} (I3)`);
        }
      }
    }
  }
  // I2: jeder Mapping-Selektor trifft >= 1 Konto; feuert nur bei vollständig leerem Selektor.
  for (const mapping of mappings) {
    checkMappingSelectors(mapping, accountNumbers);
  }
  // I4: jeder vom Manifest referenzierte taxCode wird von einem tax-Modul bereitgestellt.
  for (const code of manifest.taxCodes ?? []) {
    if (typeof code === 'string' && !taxCodeCodes.has(code)) {
      throw new DomainError('E_PACK_UNRESOLVED_REF', `Manifest-taxCode ohne tax-Modul: ${code} (I4)`);
    }
  }

  // 6. packPolicy: Manifest-Kopie == aufgelöstes policy-Modul + Wertebereiche.
  const effectivePolicy = packPolicyModule ?? manifest.packPolicy;
  validatePolicyValues(effectivePolicy);
  if (packPolicyModule !== null && !samePolicy(manifest.packPolicy, packPolicyModule)) {
    throw new DomainError('E_POLICY_INVALID', 'Manifest-packPolicy weicht vom policy-Modul ab');
  }

  const synthCoaId = `${manifest.id}-coa`;
  const profile: Record<string, unknown> = {
    id: manifest.id,
    name: manifest.name ?? manifest.id,
    version: manifest.version,
    chartOfAccounts: synthCoaId,
    taxCodes: manifest.taxCodes ?? taxCodes.map((t) => asString(t.code) ?? ''),
    mappings: mappings.map((m) => asString(m.id) ?? ''),
    defaults: manifest.defaults ?? {},
  };

  return {
    id: manifest.id,
    version: manifest.version,
    chartOfAccounts: { accounts },
    taxCodes,
    mappings,
    assetAccounts,
    depreciation,
    packPolicy: effectivePolicy,
    profile,
  };
}

/** Wandelt einen ResolvedPack in das `ruleModules`-Bündel, das `TenantFactory` konsumiert. */
export function ruleModulesFromResolved(pack: ResolvedPack): Record<string, unknown> {
  return {
    profiles: [pack.profile],
    chartsOfAccounts: [{ id: asString(pack.profile.chartOfAccounts) ?? '', accounts: pack.chartOfAccounts.accounts }],
    taxCodes: pack.taxCodes,
    mappings: pack.mappings,
    assetAccounts: pack.assetAccounts ?? {},
    depreciation: pack.depreciation ?? {},
    packPolicy: pack.packPolicy,
  };
}

/** Kahn-artige topologische Sortierung; stabiler Tie-Break per (kind|id)-Codepoint. Zyklus → INCOHERENT. */
function topoSort(modules: PackModule[], present: Set<string>): PackModule[] {
  const out: PackModule[] = [];
  const done = new Set<string>();
  const remaining = [...modules];
  while (remaining.length > 0) {
    const ready = remaining.filter((m) =>
      (m.dependsOn ?? [])
        .filter((dep) => present.has(refKey(dep.kind, dep.id)))
        .every((dep) => done.has(refKey(dep.kind, dep.id))),
    );
    if (ready.length === 0) {
      throw new DomainError('E_PACK_INCOHERENT', 'Abhängigkeits-Zyklus');
    }
    ready.sort((a, b) => byCodepoint(refKey(a.kind, a.id), refKey(b.kind, b.id)));
    const next = ready[0]!;
    out.push(next);
    done.add(refKey(next.kind, next.id));
    remaining.splice(remaining.indexOf(next), 1);
  }
  return out;
}

function checkMappingSelectors(mapping: Record<string, unknown>, accountNumbers: Set<string>): void {
  const numbers = [...accountNumbers];
  const visit = (position: Record<string, unknown>): void => {
    for (const selector of recordList(position.accounts)) {
      let hits = 0;
      if (Array.isArray(selector.numbers)) {
        hits = selector.numbers.filter((n) => typeof n === 'string' && accountNumbers.has(n)).length;
      } else {
        const from = asString(selector.from);
        const to = asString(selector.to);
        if (from !== null && to !== null) {
          hits = numbers.filter((n) => byCodepoint(n, from) >= 0 && byCodepoint(n, to) <= 0).length;
        }
      }
      if (hits === 0) {
        throw new DomainError('E_PACK_UNRESOLVED_REF', 'Mapping-Selektor trifft kein Konto (I2)');
      }
    }
    for (const child of recordList(position.children)) {
      visit(child);
    }
  };
  for (const position of recordList(mapping.positions)) {
    visit(position);
  }
}

function validatePolicyValues(policy: Record<string, unknown>): void {
  const roundingMode = asString(policy.roundingMode);
  if (roundingMode === null || !ROUNDING_MODES.includes(roundingMode)) {
    throw new DomainError('E_POLICY_INVALID', `Ungültiger roundingMode: ${String(policy.roundingMode)}`);
  }
  const granularity = asString(policy.taxRoundingGranularity);
  if (granularity === null || !TAX_GRANULARITIES.includes(granularity)) {
    throw new DomainError('E_POLICY_INVALID', `Ungültige taxRoundingGranularity: ${String(policy.taxRoundingGranularity)}`);
  }
  const scale = policy.currencyScale;
  if (typeof scale !== 'number' || !Number.isInteger(scale) || scale < 0 || scale > 4) {
    throw new DomainError('E_POLICY_INVALID', `currencyScale außerhalb 0–4: ${String(scale)}`);
  }
}

function samePolicy(a: Record<string, unknown>, b: Record<string, unknown>): boolean {
  return (
    a.roundingMode === b.roundingMode &&
    a.taxRoundingGranularity === b.taxRoundingGranularity &&
    a.currencyScale === b.currencyScale
  );
}
