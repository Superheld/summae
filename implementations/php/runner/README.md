# Fixture runner

Reads `testing/testsuite/fixtures/**.json`, builds in-memory tenants from `setup`,
runs `steps` and `projections` and compares according to the runner contract
(`testing/testsuite/README.md`): subset comparison, placeholders (`$1`, `$V1`, …),
exact error-code comparison, suite double-run determinism.

It is the oldest piece of the implementation after the shared kernel: the contract came
before the domain code that has to satisfy it.
