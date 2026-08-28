# Surgery B — split `ledger.ts` (#22) — ✅ DONE

> **Decided "go" and executed. Verified against the code on 2026-08-27.** This page is kept for the
> reasoning, not as an open question — do not re-ask it.
>
> `ledger.ts` is a facade today, in **both** languages, and `core/src/CLAUDE.md` describes the result
> as the current state. What was actually built differs from the proposal below in one deliberate
> way: `post` and its line parsing **stayed in the facade** rather than moving into a `PostingService`,
> and the lifecycle methods went to a `FiscalPeriodService` rather than a `LifecycleService`.
>
> | Proposed below | Built |
> |---|---|
> | `PostingService` | — `post`/`correct`/`finalize`/`reverse` and the shared line parsing stayed in `ledger.ts` |
> | `SettlementService` | `settlement-service.ts` / `SettlementService.php` |
> | `LifecycleService` | `fiscal-period-service.ts` / `FiscalPeriodService.php` |
> | `ChartAdminService` | `chart-admin-service.ts` / `ChartAdminService.php` |
> | (not proposed) | `audit-writer.ts` / `AuditWriter.php`, `lookups.ts` / `Lookups.php` — the shared helpers the "Against" section below worried about |
>
> `ledger.ts` went from 725 lines to 579 (PHP: 749). The shared-helper problem the proposal named as
> the main argument against turned out to have its own answer — a writer and a lookup module — rather
> than blurring the new seams.

---


`ledger.ts` (Node, 725 lines) / `Ledger.php` (~900) is the orchestrator: it fuses `post`
(substrate), `settle`/`reverse` (expansion) and `closePeriod`/`closeFiscalYear` (constraint)
into one class. The directory split (slices 1–4) is done; this is the **method-level**
disentanglement. It is a **pure-structure, byte-identical** refactor — no behavior change.

> *Original framing, kept as written:* tracking/proposal branch, not merged. This is the information
> to decide **go or leave** — I did not perform the split, because it's high-churn with zero
> functional benefit and reasonable engineers differ on whether a working orchestrator should be
> broken up.

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
