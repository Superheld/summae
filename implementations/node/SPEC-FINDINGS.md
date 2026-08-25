# SPEC-FINDINGS — moved

The findings register is **one file for both implementations**, at the repository root:
[`SPEC-FINDINGS.md`](../../SPEC-FINDINGS.md).

It used to be two, and the language-neutral findings lived in both. Seven `SPEC-` entries were
byte-identical copies and **SPEC-014 had already drifted** — the PHP copy carried the decision and
its reasoning, the Node copy had shrunk to a summary ending in "full write-up on the PHP side".
A copy that nothing compares drifts; that is the rule this project applies to code, and the file
that records such failures had become one.

Record a new finding there, in the series it belongs to: `SPEC-nnn` (spec / fixture / model
contradict each other) · `SPEC-Cnn` (cross-implementation) · `IMPL-nnn` (implementation defect).
Which language you found it in is a sentence in the entry, not a directory — most of them turn out
to concern both.
