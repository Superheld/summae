# Regression scenarios

Language-neutral scenarios that pin **defects we have fixed**, so they cannot come back.
Same format and same runner as the walkthrough scenarios in
`docs/handbuch/examples/scenarios/` — but a different purpose, which is why they live apart:

|  | `docs/handbuch/examples/scenarios/` | here |
|---|---|---|
| what it is | the user documentation in executable form | a regression guard |
| audience | a reader following the handbook | the build |
| when to add | a new shipped configuration (pack, `rules.json`) | a fixed defect |
| may contain nonsense input | no — every step is exemplary | **yes, that is the point** |

A forged tax tag or an amount in exponent notation has no business in a page a reader
copies from. Keeping them apart means the documentation stays exemplary while the guard
stays adversarial.

**Not to be confused with `testsuite/`.** That directory is the normative compatibility
contract, mirrored read-only from the knowledge base (`make sync`, `rsync --delete`) and
never edited here. These scenarios are locally owned: they cover the CLI surface, the
workspace and the pack library, which the fixtures do not reach.

Both directories are read by the same tests, in both languages:
`implementations/node/packages/cli/test/walkthrough.test.ts` and
`implementations/php/packages/cli/tests/WalkthroughTest.php`.

Format: `docs/handbuch/examples/scenarios/README.md`.
