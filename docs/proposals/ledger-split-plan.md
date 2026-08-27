> **Merged 2026-08-27 for preservation.** These were tracking branches that carried the memo
> and nothing else; the branches are gone, the open decisions are not. Written before the
> pack library and the knowledge base moved into this repository (2026-08-26), so anything
> here about `make sync` or an external store has been corrected, not left standing.

# Surgery B — split `ledger.ts` (#22) — plan for a go/leave decision

`ledger.ts` (Node, 725 lines) / `Ledger.php` (~900) is the orchestrator: it fuses `post`
(substrate), `settle`/`reverse` (expansion) and `closePeriod`/`closeFiscalYear` (constraint)
into one class. The directory split (slices 1–4) is done; this is the **method-level**
disentanglement. It is a **pure-structure, byte-identical** refactor — no behavior change.

> Tracking/proposal branch, not merged. This is the information to decide **go or leave** — I did
> not perform the split, because it's high-churn with zero functional benefit and reasonable
> engineers differ on whether a working orchestrator should be broken up.

## Proposed decomposition (by responsibility / policy kind)

Extract four focused collaborators; `Ledger` becomes a thin **facade** that keeps the exact
public surface `TenantOperations` calls and delegates:

| New collaborator | Methods moved | Policy kind |
|---|---|---|
| **PostingService** | `post`, `createOpenItems`, + line helpers (`parseLine`, `resolveLines`, `assertBalanced`, `parseEntryDate`, `openPeriodFor`, `requireVoucher`) | substrate |
| **SettlementService** | `settle`, `reverse`, `parseSettlementMoney` | expansion |
| **LifecycleService** | `correct`, `finalize`, `closePeriod`, `reopenPeriod`, `closeFiscalYear` | constraint / record lifecycle |
| **ChartAdminService** | `createAccount`, `lockAccount`, `importChartOfAccounts`, `createFiscalYear`, `buildAccount` | setup |

Shared helpers (`actor`, `now`, `requireEntry`, `requireFiscalYear`, `periodNumber`) move with
their primary user or into a small shared base; the repos (journal/accounts/fiscalYears/openItems/
vouchers/audit + clock/ids) are injected into each service by the facade.

## Tradeoffs

**For:** each unit is small and single-purpose; the seams match the three policy kinds (the
architecture's own axis); easier to read and unit-test in isolation.

**Against:** the orchestrator works and is well-tested; the split is **churn with no functional
benefit**; ~900 lines moving across both languages risks subtle byte differences (caught by
conformance `--strict` + the SF-15 cross-test, but still a real review burden); shared private
helpers blur the clean lines (some are used by 2–3 of the new services).

## Recommendation

Defensible to **leave it** — "bewährt schlägt elegant", and a working orchestrator isn't a defect.
If you value the policy-kind-aligned structure for future growth, **go** — it's safe (byte-identical,
fully guarded), just churn. Either way the call is taste/structure, not correctness, which is why
it's yours. If "go": I'd do it on one branch, prove conformance `--strict` + cross-test byte-identical
both languages, and update the `core/src/CLAUDE.md` "Gated" section.
