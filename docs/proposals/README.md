# Proposals — decision memos, with their state on the first line

Memos written to put a decision in front of a human: the options, the tradeoffs, a
recommendation. They are **not** user documentation and not specifications — the manual is
[`../handbuch/`](../handbuch/README.md), the requirements are in `knowledge/30-anforderungen/`.

The first three lived on tracking branches until 2026-08-27, which is exactly why this index exists.
A memo on an unmerged branch is invisible; a merged memo with no state on it is worse, because it
reads like an open question forever and gets re-asked. **The state belongs in the title, and it
belongs here.**

| Memo | State | What is left to do |
|---|---|---|
| [`ledger-split-plan.md`](ledger-split-plan.md) | ✅ **done** | Nothing. Decided "go" and executed in both languages; `ledger.ts` is a facade and `core/src/CLAUDE.md` describes the result as current. The memo records what was built differently from what it proposed. |
| [`spec-fixture-audit.md`](spec-fixture-audit.md) | ✅ **done** | Nothing pending. Depth A was chosen, executed, and came back clean. Depth B remains an option that was deliberately not taken — for assurance, not because anything is suspected. |
| [`us-pack-signoffs.md`](us-pack-signoffs.md) | ✅ **done 2026-08-28** | All six decided. Five confirm what already ships (one of them, `EXEMPT`, had been built since 0.5.0 while the memo still asked for it); the sixth is a deliberate **non**-change — `SALETAX` reads like a typo and stays, because nine fixtures drive it as input while proving the shipped pack. The product did not move; the documents that were wrong about it did. |
| [`gdpr-open-rights.md`](gdpr-open-rights.md) | ⚠️ **open** | The four GDPR rows the erasure work did not close: no assembled Art. 15 answer, restriction as a label rather than a stop, and a free-text surface that is visible but not narrowed. Art. 18 is deliberately blocked on a product question, not on effort. |
| [`constraint-vocabulary.md`](constraint-vocabulary.md) | ✅ **done 2026-08-28** | Nothing pending. **A was declined**, and not for cost: checking its motivating rule against the shipped packs showed the memo was wrong about what `de` forbids, and that "an exempt sale may not carry output tax" is unsafe as a per-entry predicate in *both* packs — a collective invoice legitimately mixes a taxable and an exempt supply in one entry. The subtype vocabulary was closed anyway (F-CORE-046), on its own merits. **B was built** as `appliesWhen` (`legalForm`, `taxationMethod`), plus one word the memo did not foresee needing: `accountUsageRules`, because the memo's way of saying "this account may not be used" (`forbidAccountIn: 0000–9999`) depends on how a foreign chart numbers its accounts. Shipped in `de@2026.10`. |
| [`library-boundary.md`](library-boundary.md) | ⚠️ **open — direction confirmed 2026-08-29** | The criterion that decides what belongs in the library (*the books must hold it because the law says so*), and the three designs it produced: stock valuation without a product master, a `DocumentStore` port for the voucher document, and the § 15a arithmetic. None built. It also carries what the boundary audit found in both directions — one candidate application→library, nothing library→application — and the order of work, plus §10: whether the system design holds. It mostly does — four items are the existing repertoire applied to missing positions; two fit no policy kind as first framed and are reframed (§ 15a as an expansion, not a projection; Bewertungsstetigkeit as a report, not a constraint); `DocumentStore` is a new *kind* of port; foreign currency is the only item that reaches the substrate. The legal gaps it uncovered are their own census, [`../hgb-conformance.md`](../hgb-conformance.md). |
| [`de-pack-vat-completeness.md`](de-pack-vat-completeness.md) | ⚠️ **open** | § 14c, § 15a and OSS. Argued as product scope rather than a GoBD gap, with the reasoning, so the ⚠️ is not re-litigated. § 14c is small and has a clean seam; the other two are projects. |

**Closing one:** put the state in its title, say in one line what was actually built (especially
where that differs from the proposal), and update the row above. Do not delete the memo — the
reasoning is the part worth keeping, and a decision without its reasoning gets re-litigated by the
next reader who has only the outcome.

> Two of these predate the pack library and knowledge base moving into this repository
> (2026-08-26). References to `make sync` or to an external no-undo store were corrected on merge
> rather than left standing; the decisions themselves are untouched.
