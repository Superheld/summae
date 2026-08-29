import type { Currency } from '../../substrate/currency.js';
import { FORMAT_VERSION } from '../../substrate/format-version.js';
import type { Uuid } from '../../substrate/uuid.js';

/**
 * Technical system description (F-IO-007) — the building block of a Verfahrensdokumentation
 * the *package* can supply (GoBD Rz. 151 ff.).
 *
 * The Verfahrensdokumentation has four parts: general description, user documentation,
 * technical system documentation, operating documentation. Three of them describe *your*
 * installation and processes and no library can write them. The technical one is different:
 * what the engine enforces, which operations exist, how the journal behaves and what the
 * stored format looks like are facts about the software, and stating them by hand means
 * stating them wrong within a release.
 *
 * So this projection reports them from the same constants the engine runs on. It is
 * deliberately **not** the whole document — it is the section an auditor would otherwise ask
 * the vendor for, in a form you paste into yours.
 *
 * Deterministic and tenant-independent apart from the identity block: same version of
 * summae, same description.
 *
 * `pack` names the manifest the tenant was composed from, or is null when it was built from
 * an inline rule bundle — in which case there is no manifest to name and a
 * Verfahrensdokumentation has to state the rule source by hand.
 */

/**
 * Every operation and projection the dispatcher answers. This is the list the description
 * publishes; `tenant-operations-contract.test.ts` holds its own literal copy and asserts
 * both agree, so a case dropped from the dispatcher and a name dropped from here cannot
 * cancel each other out.
 *
 * The test asks **both** directions, and the second one was missing for a long time: every
 * published name resolves to a handler, *and* every routed name is published. Seven finished,
 * documented, fixture-covered capabilities were routed and unpublished — an embedding app
 * validating its calls against this list could not call them at all, which is how it was
 * found. A surface larger than its declaration is the same defect as one smaller than it.
 */
export const API_OPERATIONS = [
   'acquireAsset', 'allocate', 'appropriateResult', 'bookSpecialDepreciation', 'closeFiscalYear', 'closePeriod',
   'correct', 'createAccount', 'createFiscalYear', 'createPartner', 'createVoucher',
   'deactivatePartner', 'defineDimensionType', 'defineDimensionValue', 'disposeAsset', 'erasePartner',
   'expandTax', 'finalize', 'importChartOfAccounts', 'importMapping', 'lockAccount', 'post',
   'postVoucher', 'reactivatePartner', 'releaseCosting', 'reopenPeriod', 'reportAssetUsage',
   'recognizeProvision', 'releaseProvision', 'remeasureProvision',
  'reverse', 'runCosting', 'runDepreciation', 'setAllocationScheme', 'setEntityProfile', 'setTaxProfile',
  'useProvision', 'valuateInventory', 'settle',
   'unlockAccount', 'updatePartner', 'writeDownAsset',
] as const;

export const API_PROJECTIONS = [
  'accountSheet', 'accounts', 'assetRegister', 'auditDataExport', 'auditLog', 'auditTrailIntegrity', 'balanceSheet',
  'cashBasisReport', 'cashJournal', 'costAllocationSheet', 'costingRuns', 'datevExport', 'duplicateVouchers', 'ecSalesList',
  'fiscalYears', 'gdpduExport', 'incomeStatement', 'inventoryValuation', 'journal', 'journalExport',
  'measurementConsistency', 'openItems',
  'personalDataDescription', 'overheadRates',
  'productionCost', 'provisionRegister', 'systemDescription', 'tenantConfiguration', 'trialBalance',
  'unappropriatedResult', 'unfinalizedEntries', 'vatReturn',
] as const;

/**
 * What the engine guarantees, in the words an auditor uses. Each entry names the mechanism
 * that makes it true, so the claim can be checked rather than believed — the same discipline
 * `docs/gobd-conformance.md` applies at the level of the whole system.
 */
const INVARIANTS: ReadonlyArray<{ id: string; statement: string; enforcedBy: string }> = [
  {
    id: 'balanced-posting',
    statement: 'Every posting balances: the sum of its lines is zero.',
    enforcedBy: 'Rejected on write with E_ENTRY_UNBALANCED; never checked on read.',
  },
  {
    id: 'append-only-journal',
    statement: 'The journal is append-only. Nothing is deleted, nothing is overwritten.',
    enforcedBy: 'No delete or update path exists on the journal repository.',
  },
  {
    id: 'immutable-after-finalization',
    statement: 'A finalized posting cannot be changed; a correction is a reversal that references the original.',
    enforcedBy: 'JournalEntry refuses the change itself (E_ENTRY_FINALIZED); reverse writes a new entry carrying `reverses`.',
  },
  {
    id: 'gapless-numbering',
    statement: 'Journal numbers are gapless; the number is assigned atomically while posting.',
    enforcedBy: 'Number assignment is the serialization point of `post` in the persistence adapter.',
  },
  {
    id: 'mandatory-voucher',
    statement: 'No posting without exactly one voucher reference.',
    enforcedBy: 'voucherId is not nullable; an unknown one is E_VOUCHER_UNKNOWN.',
  },
  {
    id: 'closed-periods',
    statement: 'A closed period accepts no further postings; periods close in order.',
    enforcedBy: 'E_PERIOD_CLOSED on every write into a closed period.',
  },
  {
    id: 'projections-not-stored-balances',
    statement: 'Every balance, trial balance, balance sheet, income statement and VAT return is recomputed from the journal.',
    enforcedBy: 'No balance is stored anywhere; there is no second source to diverge from.',
  },
  {
    id: 'exact-money',
    statement: 'Amounts are exact decimals, never floating point. Rounding is commercial half-up, away from zero.',
    enforcedBy: 'Money is built on a decimal library (brick/math in PHP, big.js in Node).',
  },
  {
    id: 'determinism',
    statement: 'The same input produces a byte-identical result, in every implementation.',
    enforcedBy: 'Injectable Clock and IdGenerator, canonical JSON (RFC 8785), sorting by Unicode code point; the conformance suite runs twice and compares.',
  },
  {
    id: 'audit-trail',
    statement: 'Every state-changing operation writes an audit record with actor, timestamp, object and before/after values.',
    enforcedBy: 'Enumerating contract test over all state-changing operations, in both implementations.',
  },
  {
    id: 'zoneless-dates',
    statement: 'Posting and voucher dates carry no time zone, so no shift can move a posting into another period.',
    enforcedBy: 'CalendarDate stores an ISO date and does its arithmetic without the host date type.',
  },
];

/** What the audit trail records, so a reader can tell completeness from a sample. */
/**
 * Exported because `audit-trail-contract.test.ts` holds it against the pairs the operations
 * *actually* write, in both directions — the same guard `capabilities` has. It used to be a
 * hand-kept literal that nothing compared to reality, and it had already fallen behind once
 * (0.11.0, where it under-reported what the trail records). A list published to an auditor is a
 * claim, and a claim without a test is the thing this project calls a gate gap.
 */
export const AUDITED_EVENTS: ReadonlyArray<{ objectType: string; actions: readonly string[] }> = [
  { objectType: 'journalEntry', actions: ['created', 'corrected', 'finalized', 'reversed'] },
  { objectType: 'voucher', actions: ['created'] },
  { objectType: 'account', actions: ['created', 'locked', 'unlocked'] },
  { objectType: 'openItem', actions: ['settled', 'cancelled'] },
  { objectType: 'partner', actions: ['created', 'updated', 'deactivated', 'reactivated', 'erased'] },
  { objectType: 'fiscalYear', actions: ['created', 'closed'] },
  { objectType: 'period', actions: ['closed', 'reopened'] },
  { objectType: 'taxProfile', actions: ['changed'] },
  { objectType: 'entityProfile', actions: ['changed'] },
  { objectType: 'mapping', actions: ['imported'] },
  { objectType: 'allocationScheme', actions: ['changed'] },
  { objectType: 'asset', actions: ['acquired', 'disposed', 'usageReported', 'specialDepreciationBooked', 'writtenDown'] },
  { objectType: 'dimensionType', actions: ['created'] },
  { objectType: 'dimensionValue', actions: ['created'] },
  { objectType: 'depreciationRun', actions: ['completed'] },
  { objectType: 'inventoryValuation', actions: ['valued'] },
  { objectType: 'provision', actions: ['recognized', 'used', 'released', 'remeasured'] },
  { objectType: 'costingRun', actions: ['created', 'released'] },
];

/**
 * What this package deliberately does NOT do. An auditor reading a system description needs
 * the boundary as much as the capability list, and a Verfahrensdokumentation that claims
 * more than the software does is worse than one that claims less.
 */
const NOT_PROVIDED: readonly string[] = [
  'No user model and no authentication: `actor` is recorded as supplied by the caller, never verified.',
  'No access control, no separation of duties.',
  'No document storage: vouchers are referenced, the files live elsewhere.',
  'No retention or deletion logic; no automatic deletion by date.',
  'No submission to authorities (ELSTER), no e-invoice creation or parsing, no banking, no POS/TSE.',
  'No enforcement of national recording deadlines — the `unfinalizedEntries` projection reports them.',
  'No mapping onto the GoBD Z3 Beschreibungsstandard (index.xml); `journalExport` supplies the self-describing data set it is built from.',
  'Guarantees hold for operations made through the documented API; direct database writes bypass all of them.',
];

export class SystemDescriptionProjection {
  constructor(
    private readonly tenantId: Uuid,
    private readonly tenantName: string,
    private readonly baseCurrency: Currency,
    /** Null when the tenant was composed from an inline bundle: there is no manifest to name. */
    private readonly packIdentity: { id: string; version: string } | null = null,
    /**
     * The tax profile the engine is actually running on (F-TAX-003).
     *
     * Nothing reported it, so an embedding could display only what it had written itself — the same
     * value by construction *in that embedding*, which is a property of one caller and not a
     * guarantee. It belongs here rather than in a projection of its own: this is the projection that
     * already describes the tenant, and a Verfahrensdokumentation has to state which taxation method
     * the books were kept under.
     */
    private readonly taxProfile: Record<string, unknown> | null = null,
    /**
     * What the embedding declares about the identity behind `actor` (SPEC-020, reported by an
     * embedding app as its F-30).
     *
     * summae is handed an actor string and cannot know where it came from — that is what
     * `auditTrail.actorIsAuthenticated: false` has always said, and it stays true. The trouble was
     * what the field gets *used for*: an application generating the technical part of a
     * Verfahrensdokumentation put it in as "Urheber geprüft: **nein**", and then grew a login. The
     * document went on telling an auditor that every entry's author is unverified about an
     * installation where a password had been proved before the actor was ever set.
     *
     * So the only party that can know says so, and summae reports it **as a declaration** rather
     * than as a finding of its own. `null` — the default, and what every embedding gets until it
     * speaks — means nothing was declared, which is not the same as "no".
     *
     * Deliberately **not** stored with the tenant. This describes the running installation, not the
     * books: an embedding that drops its login tomorrow must not leave yesterday's claim behind in
     * a record. It is passed on every construction, like the pack.
     */
    private readonly actorAuthentication: { declared: boolean; method: string | null } | null = null,
  ) {}

  compute(_params: Record<string, unknown>): Record<string, unknown> {
    return {
      formatVersion: FORMAT_VERSION,
      tenant: {
        id: this.tenantId.value,
        name: this.tenantName,
        baseCurrency: this.baseCurrency.code,
      },
      pack: this.packIdentity === null ? null : { ...this.packIdentity },
      taxProfile: this.taxProfile === null ? null : { ...this.taxProfile },
      journal: {
        appendOnly: true,
        ordering: 'sequenceNumber',
        lifecycle: ['entered', 'finalized'],
        correction: 'reversal with back-reference (`reverses` / `reversedBy`)',
        dates: {
          voucherDate: 'date on the voucher',
          entryDate: 'bookkeeping date, zoneless',
          recordedAt: 'moment of recording, canonical UTC timestamp',
        },
      },
      invariants: INVARIANTS.map((i) => ({ ...i })),
      auditTrail: {
        events: AUDITED_EVENTS.map((e) => ({ objectType: e.objectType, actions: [...e.actions] })),
        actorIsAuthenticated: false,
        actorAuthentication: {
          // Never goes stale, whatever any embedding does: this library authenticates nobody.
          byLibrary: false,
          // What the embedding states about itself. `null` = it has not said, which a generator
          // must not turn into "no" — an unanswered question and a denial read differently to an
          // auditor.
          declaredByEmbedding: this.actorAuthentication === null ? null : this.actorAuthentication.declared,
          method: this.actorAuthentication === null ? null : this.actorAuthentication.method,
        },
        // What makes the trail checkable rather than merely trusted (format 0.8). Published here
        // because an auditor reads this block to learn what the trail is; a chain nobody is told
        // about protects nobody.
        hashChain: {
          algorithm: 'sha256-over-canonical-json',
          field: 'previousRecordHash',
          verifiedBy: 'auditTrailIntegrity',
          note:
            'Each record carries the hash of its predecessor, computed over canonical JSON (RFC 8785). '
            + 'Changing or removing a record breaks the link at its successor. Records erased under a '
            + 'privacy right leave a shell that keeps both hashes, so a lawful erasure stays distinguishable '
            + 'from a manipulation; a shell\'s own content can no longer be verified, which is what an erasure '
            + 'means. Records written before format 0.8 carry no hash and are reported as unchained, never as '
            + 'broken. A chain cannot notice records dropped from the END of the trail — compare the published '
            + 'head against one you kept.',
        },
        note:
          'The actor is recorded as supplied by the caller; summae authenticates nobody, which is what '
          + 'actorAuthentication.byLibrary states and what actorIsAuthenticated has always meant. Whether the '
          + 'identity behind it was proved is a fact only the embedding knows, and it is reported here as that '
          + "embedding's declaration — never as summae's own finding.",
      },
      capabilities: {
        operations: [...API_OPERATIONS],
        projections: [...API_PROJECTIONS],
      },
      notProvided: [...NOT_PROVIDED],
    };
  }
}
