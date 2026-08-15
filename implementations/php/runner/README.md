# Fixture runner (JOB-002)

Reads `testing/testsuite/fixtures/**.json`, builds in-memory tenants from `setup`,
runs `steps` and `projections` and compares according to the runner contract
(`testing/testsuite/README.md`): subset comparison, placeholders (`$1`, `$V1`, …),
exact error-code comparison, suite double-run determinism.

Created in JOB-002 — after the shared kernel (JOB-001).
