# SPEC-FINDINGS — moved

The findings register is **one register for both implementations**, at the repository root, split by
state rather than by language:

- [`SPEC-FINDINGS.md`](../../SPEC-FINDINGS.md) — **what is open.** Short on purpose: told to look at
  the open findings, you should be able to read all of it and carry nothing else.
- [`SPEC-FINDINGS-RESOLVED.md`](../../SPEC-FINDINGS-RESOLVED.md) — everything decided, in full, plus
  the status table over both. This is what a comment saying "see IMPL-025" resolves to.

It used to be two files split by *language*, and the language-neutral findings lived in both: seven
`SPEC-` entries were byte-identical copies and SPEC-014 had already drifted. A split by language
duplicates; a split by state does not — an entry moves exactly once, when it is closed.

Record a new finding in the open file, in the series it belongs to: `SPEC-nnn` (spec / fixture /
model contradict each other) · `SPEC-Cnn` (cross-implementation) · `IMPL-nnn` (implementation
defect). Which language you found it in is a sentence in the entry, not a directory.
