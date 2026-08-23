<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\FormatVersion;
use Summae\Core\Substrate\Uuid;

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
 *
 * The SAME lists live in the Node system-description.ts.
 */
final readonly class SystemDescriptionProjection
{
    /**
     * Every operation and projection the dispatcher answers. This is the list the description
     * publishes; `TenantOperationsContractTest` holds its own literal copy and asserts both
     * agree, in **both** directions: every published name resolves to a handler, and every
     * routed name is published. The second direction was missing for a long time — seven
     * finished, documented, fixture-covered capabilities were routed and unpublished, and an
     * embedding app validating its calls against this list could not call them at all. A
     * surface larger than its declaration is the same defect as one smaller than it.
     *
     * @var list<string>
     */
    public const API_OPERATIONS = [
        'acquireAsset', 'allocate', 'bookSpecialDepreciation', 'closeFiscalYear', 'closePeriod',
        'correct', 'createAccount', 'createFiscalYear', 'createPartner', 'createVoucher',
        'deactivatePartner', 'defineDimensionType', 'defineDimensionValue', 'disposeAsset',
        'expandTax', 'finalize', 'importChartOfAccounts', 'importMapping', 'lockAccount', 'post',
        'postVoucher', 'reactivatePartner', 'releaseCosting', 'reopenPeriod', 'reportAssetUsage',
        'reverse', 'runCosting', 'runDepreciation', 'setAllocationScheme', 'setTaxProfile',
        'settle', 'unlockAccount', 'updatePartner', 'writeDownAsset'
    ];

    /** @var list<string> */
    public const API_PROJECTIONS = [
        'accountSheet', 'accounts', 'assetRegister', 'auditDataExport', 'auditLog', 'balanceSheet',
        'cashBasisReport', 'cashJournal', 'costAllocationSheet', 'datevExport', 'ecSalesList',
        'fiscalYears', 'incomeStatement', 'journal', 'journalExport', 'openItems', 'overheadRates',
        'productionCost', 'systemDescription', 'trialBalance', 'unfinalizedEntries', 'vatReturn'
    ];

    /**
     * What the engine guarantees, in the words an auditor uses. Each entry names the mechanism
     * that makes it true, so the claim can be checked rather than believed — the same
     * discipline `docs/gobd-conformance.md` applies at the level of the whole system.
     *
     * @var list<array{id: string, statement: string, enforcedBy: string}>
     */
    private const INVARIANTS = [
        [
            'id' => 'balanced-posting',
            'statement' => 'Every posting balances: the sum of its lines is zero.',
            'enforcedBy' => 'Rejected on write with E_ENTRY_UNBALANCED; never checked on read.',
        ],
        [
            'id' => 'append-only-journal',
            'statement' => 'The journal is append-only. Nothing is deleted, nothing is overwritten.',
            'enforcedBy' => 'No delete or update path exists on the journal repository.',
        ],
        [
            'id' => 'immutable-after-finalization',
            'statement' => 'A finalized posting cannot be changed; a correction is a reversal that references the original.',
            'enforcedBy' => 'JournalEntry refuses the change itself (E_ENTRY_FINALIZED); reverse writes a new entry carrying `reverses`.',
        ],
        [
            'id' => 'gapless-numbering',
            'statement' => 'Journal numbers are gapless; the number is assigned atomically while posting.',
            'enforcedBy' => 'Number assignment is the serialization point of `post` in the persistence adapter.',
        ],
        [
            'id' => 'mandatory-voucher',
            'statement' => 'No posting without exactly one voucher reference.',
            'enforcedBy' => 'voucherId is not nullable; an unknown one is E_VOUCHER_UNKNOWN.',
        ],
        [
            'id' => 'closed-periods',
            'statement' => 'A closed period accepts no further postings; periods close in order.',
            'enforcedBy' => 'E_PERIOD_CLOSED on every write into a closed period.',
        ],
        [
            'id' => 'projections-not-stored-balances',
            'statement' => 'Every balance, trial balance, balance sheet, income statement and VAT return is recomputed from the journal.',
            'enforcedBy' => 'No balance is stored anywhere; there is no second source to diverge from.',
        ],
        [
            'id' => 'exact-money',
            'statement' => 'Amounts are exact decimals, never floating point. Rounding is commercial half-up, away from zero.',
            'enforcedBy' => 'Money is built on a decimal library (brick/math in PHP, big.js in Node).',
        ],
        [
            'id' => 'determinism',
            'statement' => 'The same input produces a byte-identical result, in every implementation.',
            'enforcedBy' => 'Injectable Clock and IdGenerator, canonical JSON (RFC 8785), sorting by Unicode code point; the conformance suite runs twice and compares.',
        ],
        [
            'id' => 'audit-trail',
            'statement' => 'Every state-changing operation writes an audit record with actor, timestamp, object and before/after values.',
            'enforcedBy' => 'Enumerating contract test over all state-changing operations, in both implementations.',
        ],
        [
            'id' => 'zoneless-dates',
            'statement' => 'Posting and voucher dates carry no time zone, so no shift can move a posting into another period.',
            'enforcedBy' => 'CalendarDate stores an ISO date and does its arithmetic without the host date type.',
        ],
    ];

    /**
     * What the audit trail records, so a reader can tell completeness from a sample.
     *
     * @var list<array{objectType: string, actions: list<string>}>
     */
    private const AUDITED_EVENTS = [
        ['objectType' => 'journalEntry', 'actions' => ['created', 'corrected', 'finalized', 'reversed']],
        ['objectType' => 'voucher', 'actions' => ['created']],
        ['objectType' => 'account', 'actions' => ['created', 'locked', 'unlocked']],
        ['objectType' => 'openItem', 'actions' => ['settled', 'cancelled']],
        ['objectType' => 'partner', 'actions' => ['created', 'updated', 'deactivated', 'reactivated']],
        ['objectType' => 'fiscalYear', 'actions' => ['created', 'closed']],
        ['objectType' => 'period', 'actions' => ['closed', 'reopened']],
        ['objectType' => 'taxProfile', 'actions' => ['changed']],
        ['objectType' => 'mapping', 'actions' => ['imported']],
        ['objectType' => 'allocationScheme', 'actions' => ['changed']],
        ['objectType' => 'asset', 'actions' => ['acquired', 'disposed', 'usageReported', 'specialDepreciationBooked', 'writtenDown']],
        ['objectType' => 'dimensionType', 'actions' => ['created']],
        ['objectType' => 'dimensionValue', 'actions' => ['created']],
        ['objectType' => 'depreciationRun', 'actions' => ['completed']],
        ['objectType' => 'costingRun', 'actions' => ['created', 'released']],
    ];

    /**
     * What this package deliberately does NOT do. An auditor reading a system description
     * needs the boundary as much as the capability list, and a Verfahrensdokumentation that
     * claims more than the software does is worse than one that claims less.
     *
     * @var list<string>
     */
    private const NOT_PROVIDED = [
        'No user model and no authentication: `actor` is recorded as supplied by the caller, never verified.',
        'No access control, no separation of duties.',
        'No document storage: vouchers are referenced, the files live elsewhere.',
        'No retention or deletion logic; no automatic deletion by date.',
        'No submission to authorities (ELSTER), no e-invoice creation or parsing, no banking, no POS/TSE.',
        'No enforcement of national recording deadlines — the `unfinalizedEntries` projection reports them.',
        'No mapping onto the GoBD Z3 Beschreibungsstandard (index.xml); `journalExport` supplies the self-describing data set it is built from.',
        'Guarantees hold for operations made through the documented API; direct database writes bypass all of them.',
    ];

    public function __construct(
        private Uuid $tenantId,
        private string $tenantName,
        private Currency $baseCurrency,
        /**
         * Null when the tenant was composed from an inline bundle: there is no manifest to name.
         *
         * @var array{id: string, version: string}|null
         */
        private ?array $packIdentity = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        unset($params);

        return [
            'formatVersion' => FormatVersion::CURRENT,
            'tenant' => [
                'id' => $this->tenantId->value,
                'name' => $this->tenantName,
                'baseCurrency' => $this->baseCurrency->code,
            ],
            'pack' => $this->packIdentity,
            'journal' => [
                'appendOnly' => true,
                'ordering' => 'sequenceNumber',
                'lifecycle' => ['entered', 'finalized'],
                'correction' => 'reversal with back-reference (`reverses` / `reversedBy`)',
                'dates' => [
                    'voucherDate' => 'date on the voucher',
                    'entryDate' => 'bookkeeping date, zoneless',
                    'recordedAt' => 'moment of recording, canonical UTC timestamp',
                ],
            ],
            'invariants' => self::INVARIANTS,
            'auditTrail' => [
                'events' => self::AUDITED_EVENTS,
                'actorIsAuthenticated' => false,
                'note' => 'The actor is recorded as supplied by the caller. Binding it to an authenticated identity is the embedding application\'s responsibility.',
            ],
            'capabilities' => [
                'operations' => self::API_OPERATIONS,
                'projections' => self::API_PROJECTIONS,
            ],
            'notProvided' => self::NOT_PROVIDED,
        ];
    }
}
