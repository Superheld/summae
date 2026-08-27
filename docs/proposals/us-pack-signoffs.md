> **Merged 2026-08-27 for preservation.** These were tracking branches that carried the memo
> and nothing else; the branches are gone, the open decisions are not. Written before the
> pack library and the knowledge base moved into this repository (2026-08-26), so anything
> here about `make sync` or an external store has been corrected, not left standing.

# us-pack sign-offs (#31) — for Roland

Consolidated checklist of the open **human** decisions on the us-pack. None block the green
build; each is quasi-irreversible once a tenant posts against the pack, so they want a sign-off
before the pack is recommended for production. Full background:
[`knowledge/99-pack-docs/us-pack/offene-entscheidungen.md`](../../knowledge/99-pack-docs/us-pack/offene-entscheidungen.md);
this is the actionable short list.

> Tick a box (or annotate) and the decision gets folded in.

## Decisions

- [ ] **Account numbers** — the US-native chart (35 accounts, 1xxx assets … 6xxx expenses).
  Quasi-irreversible from the first posting. Approve the numbering as-is, or revise before anyone
  posts.
- [ ] **Use-tax naming** — `USETAX` posts to cost + a use-tax liability (2110) via the reverse-charge
  mechanism. Keep the name/treatment, or rename.
- [ ] **Default taxation method** — the pack defaults to **accrual** (GAAP) / quarterly. Confirm
  accrual-by-default is right for the typical US small-business target (vs. cash-basis default).
- [ ] **Multi-state strategy** — currently single sales-tax rate per tenant. Confirm "one rate per
  tenant, app handles nexus/rates" is the intended scope (vs. per-jurisdiction rates in the pack).
- [ ] **Exempt** — now postable via the `exempt` mechanism (no tax line; #29 code merged). Confirm
  the semantics: tax-free, base tagged for reporting, **not** on the EC sales list. Then the us-pack
  `EXEMPT` code should be wired to `mechanism: exempt` (knowledge-base change) + a fixture (see #29).
- [ ] **Naming deviations from the spec wording** — manifest id `us` (not "us-complete"), module id
  `us-accounts-2026` (English). Confirm or adjust.

## After sign-off

Each approved item that needs a content change is an edit to the pack module and its fixture, made
here in the repository like everything else.
