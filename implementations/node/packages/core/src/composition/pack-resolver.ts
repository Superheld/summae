import { DomainError } from '../domain-error.js';
import { mechanismFor } from '../policies/expansion/tax/tax-mechanisms.js';

/**
 * Pack resolver (`resolvePack`) — pure, side-effect-free resolution of a
 * manifest against a module store into a `ResolvedPack`. Design:
 * `_bauflow-pack-gate01/design/module-manifest-resolver.md` (§ 3 resolver semantics,
 * § 4 ResolvedPack == TenantFactory's ruleModules bundle).
 *
 * Guiding principle: no new engine capability. The resolver invents nothing; it selects,
 * checks and folds existing module data into exactly the structure that `TenantFactory`
 * today consumes hand-fed. Fails **loudly** (exactly one `E_PACK_*`/`E_POLICY_*`)
 * instead of silently computing wrong.
 *
 * Error classes (§ 3.2): `E_PACK_UNRESOLVED_REF` = a reference points to nothing;
 * `E_PACK_INCOHERENT` = references exist, but the bundle is contradictory;
 * `E_POLICY_INVALID` = policy value/copy wrong. Reference existence (step 2/3) takes
 * precedence over coherence/integrity (4/5).
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
/** Stable comparison by Unicode codepoints (determinism sort rule). */
function byCodepoint(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/**
 * Resolves a manifest against a module store. Throws `DomainError` with the
 * matching `E_PACK_*`/`E_POLICY_*` code; otherwise returns a fully sound
 * `ResolvedPack`.
 */
export function resolvePack(manifest: PackManifest, moduleSource: PackModule[]): ResolvedPack {
  // 1. Effective module list: apply overrides (remove/replace) in array order.
  const effective: ModuleRef[] = manifest.modules.map((m) => ({ ...m }));
  for (const ov of manifest.overrides ?? []) {
    const idx = effective.findIndex((m) => m.kind === ov.ref.kind && m.id === ov.ref.id);
    if (idx < 0) {
      // Override targets nothing (also: a second remove/replace on the same ref).
      throw new DomainError('E_PACK_INCOHERENT', `Override does not match: ${refKey(ov.ref.kind, ov.ref.id)}`);
    }
    if (ov.op === 'remove') {
      effective.splice(idx, 1);
    } else if (ov.op === 'replace') {
      if (!ov.with) throw new DomainError('E_PACK_INCOHERENT', 'replace override without "with"');
      effective[idx] = { ...ov.with };
    } else {
      throw new DomainError('E_PACK_INCOHERENT', `Unknown override operation: ${ov.op}`);
    }
  }

  // 2. Resolve module references against the store (version missing → highest per codepoint).
  const resolved: PackModule[] = [];
  for (const ref of effective) {
    const candidates = moduleSource.filter(
      (m) => m.kind === ref.kind && m.id === ref.id && (ref.version === undefined || m.version === ref.version),
    );
    if (candidates.length === 0) {
      throw new DomainError('E_PACK_UNRESOLVED_REF', `Module not found: ${refKey(ref.kind, ref.id)}`);
    }
    candidates.sort((a, b) => byCodepoint(a.version, b.version));
    resolved.push(candidates[candidates.length - 1]!);
  }

  // unknown kind → INCOHERENT
  for (const m of resolved) {
    if (!MODULE_KINDS.includes(m.kind as (typeof MODULE_KINDS)[number])) {
      throw new DomainError('E_PACK_INCOHERENT', `Unknown module kind: ${m.kind}`);
    }
  }

  // 3. Dependency DAG: missing dependsOn reference → UNRESOLVED_REF (before cycle check).
  const present = new Set(resolved.map((m) => refKey(m.kind, m.id)));
  for (const m of resolved) {
    for (const dep of m.dependsOn ?? []) {
      if (!present.has(refKey(dep.kind, dep.id))) {
        throw new DomainError(
          'E_PACK_UNRESOLVED_REF',
          `dependsOn points to an unlisted module: ${refKey(dep.kind, dep.id)}`,
        );
      }
    }
  }
  const sorted = topoSort(resolved, present);

  // 4. Fold (topological). Colliding contributions → INCOHERENT (no silent overwrite).
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
            throw new DomainError('E_PACK_INCOHERENT', `Duplicate account number: ${number}`);
          }
          accountNumbers.add(number);
          accounts.push(account);
        }
        break;
      case 'tax':
        for (const taxCode of recordList(m.data.taxCodes)) {
          const code = asString(taxCode.code) ?? '';
          if (taxCodeCodes.has(code)) {
            throw new DomainError('E_PACK_INCOHERENT', `Duplicate taxCode.code: ${code}`);
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
          throw new DomainError('E_PACK_INCOHERENT', `Duplicate mapping.id: ${id}`);
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
          throw new DomainError('E_PACK_INCOHERENT', 'More than one policy module');
        }
        packPolicyModule = isRecord(m.data.packPolicy) ? m.data.packPolicy : {};
        break;
    }
  }

  // 5. Referential integrity.
  // I1: taxAccount (+ inputTaxAccount on reverse_charge) exists in the chart of accounts.
  for (const taxCode of taxCodes) {
    for (const version of recordList(taxCode.versions)) {
      const taxAccount = asString(version.taxAccount);
      if (taxAccount !== null && !accountNumbers.has(taxAccount)) {
        throw new DomainError('E_PACK_UNRESOLVED_REF', `taxAccount without account: ${taxAccount} (I1)`);
      }
      if (mechanismFor(asString(version.mechanism) ?? 'standard').requiresInputTaxAccount) {
        const inputTaxAccount = asString(version.inputTaxAccount);
        if (inputTaxAccount !== null && !accountNumbers.has(inputTaxAccount)) {
          throw new DomainError('E_PACK_UNRESOLVED_REF', `inputTaxAccount without account: ${inputTaxAccount} (I1)`);
        }
      }
    }
  }
  // I3: all five assetAccounts.*Account (+ perClass) exist.
  if (assetAccounts !== null) {
    const def = isRecord(assetAccounts.default) ? assetAccounts.default : {};
    for (const key of ASSET_ACCOUNT_KEYS) {
      const number = asString(def[key]);
      if (number === null || !accountNumbers.has(number)) {
        throw new DomainError('E_PACK_UNRESOLVED_REF', `assetAccounts.${key} without account (I3)`);
      }
    }
    const perClass = isRecord(assetAccounts.perClass) ? assetAccounts.perClass : {};
    for (const cls of Object.values(perClass)) {
      if (!isRecord(cls)) continue;
      for (const value of Object.values(cls)) {
        const number = asString(value);
        if (number !== null && !accountNumbers.has(number)) {
          throw new DomainError('E_PACK_UNRESOLVED_REF', `assetAccounts.perClass without account: ${number} (I3)`);
        }
      }
    }
  }
  // I2: every mapping selector hits >= 1 account; fires only on a fully empty selector.
  for (const mapping of mappings) {
    checkMappingSelectors(mapping, accountNumbers);
  }
  // I4: every taxCode referenced by the manifest is provided by a tax module.
  for (const code of manifest.taxCodes ?? []) {
    if (typeof code === 'string' && !taxCodeCodes.has(code)) {
      throw new DomainError('E_PACK_UNRESOLVED_REF', `Manifest taxCode without tax module: ${code} (I4)`);
    }
  }

  // 6. packPolicy: manifest copy == resolved policy module + value ranges.
  const effectivePolicy = packPolicyModule ?? manifest.packPolicy;
  validatePolicyValues(effectivePolicy);
  if (packPolicyModule !== null && !samePolicy(manifest.packPolicy, packPolicyModule)) {
    throw new DomainError('E_POLICY_INVALID', 'Manifest packPolicy deviates from the policy module');
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

/** Converts a ResolvedPack into the `ruleModules` bundle that `TenantFactory` consumes. */
export function ruleModulesFromResolved(pack: ResolvedPack): Record<string, unknown> {
  // assetAccounts: resolver I3 validates the `default` form ({default:{accounts}}); the AssetService
  // reads the accounts flat → unpack to the flat form here (pack-path parity with the inline path).
  const aa = isRecord(pack.assetAccounts) ? pack.assetAccounts : null;
  const assetAccounts = aa !== null && isRecord(aa.default) ? aa.default : (aa ?? {});
  // depreciation data (gwgThresholds, usefulLife) the AssetService reads top-level → spread.
  const depreciation = isRecord(pack.depreciation) ? pack.depreciation : {};
  return {
    profiles: [pack.profile],
    chartsOfAccounts: [{ id: asString(pack.profile.chartOfAccounts) ?? '', accounts: pack.chartOfAccounts.accounts }],
    taxCodes: pack.taxCodes,
    mappings: pack.mappings,
    assetAccounts,
    ...depreciation,
    packPolicy: pack.packPolicy,
  };
}

/** Kahn-style topological sort; stable tie-break per (kind|id) codepoint. Cycle → INCOHERENT. */
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
      throw new DomainError('E_PACK_INCOHERENT', 'Dependency cycle');
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
        throw new DomainError('E_PACK_UNRESOLVED_REF', 'Mapping selector hits no account (I2)');
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
    throw new DomainError('E_POLICY_INVALID', `Invalid roundingMode: ${String(policy.roundingMode)}`);
  }
  const granularity = asString(policy.taxRoundingGranularity);
  if (granularity === null || !TAX_GRANULARITIES.includes(granularity)) {
    throw new DomainError('E_POLICY_INVALID', `Invalid taxRoundingGranularity: ${String(policy.taxRoundingGranularity)}`);
  }
  const scale = policy.currencyScale;
  if (typeof scale !== 'number' || !Number.isInteger(scale) || scale < 0 || scale > 4) {
    throw new DomainError('E_POLICY_INVALID', `currencyScale outside 0–4: ${String(scale)}`);
  }
}

function samePolicy(a: Record<string, unknown>, b: Record<string, unknown>): boolean {
  return (
    a.roundingMode === b.roundingMode &&
    a.taxRoundingGranularity === b.taxRoundingGranularity &&
    a.currencyScale === b.currencyScale
  );
}
