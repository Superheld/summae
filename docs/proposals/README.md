# Proposals — decision memos, with their state on the first line

Memos written to put a decision in front of a human: the options, the tradeoffs, a
recommendation. They are **not** user documentation and not specifications — the manual is
[`../handbuch/`](../handbuch/README.md), the requirements are in `knowledge/30-anforderungen/`.

These three lived on tracking branches until 2026-08-27, which is exactly why this index exists.
A memo on an unmerged branch is invisible; a merged memo with no state on it is worse, because it
reads like an open question forever and gets re-asked. **The state belongs in the title, and it
belongs here.**

| Memo | State | What is left to do |
|---|---|---|
| [`ledger-split-plan.md`](ledger-split-plan.md) | ✅ **done** | Nothing. Decided "go" and executed in both languages; `ledger.ts` is a facade and `core/src/CLAUDE.md` describes the result as current. The memo records what was built differently from what it proposed. |
| [`spec-fixture-audit.md`](spec-fixture-audit.md) | ✅ **done** | Nothing pending. Depth A was chosen, executed, and came back clean. Depth B remains an option that was deliberately not taken — for assurance, not because anything is suspected. |
| [`us-pack-signoffs.md`](us-pack-signoffs.md) | ✅ **done 2026-08-28** | All six decided. Five confirm what already ships (one of them, `EXEMPT`, had been built since 0.5.0 while the memo still asked for it); the sixth is a deliberate **non**-change — `SALETAX` reads like a typo and stays, because nine fixtures drive it as input while proving the shipped pack. The product did not move; the documents that were wrong about it did. |

**Closing one:** put the state in its title, say in one line what was actually built (especially
where that differs from the proposal), and update the row above. Do not delete the memo — the
reasoning is the part worth keeping, and a decision without its reasoning gets re-litigated by the
next reader who has only the outcome.

> Two of these predate the pack library and knowledge base moving into this repository
> (2026-08-26). References to `make sync` or to an external no-undo store were corrected on merge
> rather than left standing; the decisions themselves are untouched.
