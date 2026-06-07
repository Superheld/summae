# Fixture-Runner (JOB-002)

Liest `testsuite/fixtures/**.json`, baut In-Memory-Mandanten aus `setup`,
führt `steps` und `projections` aus und vergleicht nach dem Runner-Kontrakt
(`testsuite/README.md`): Teilmengen-Vergleich, Platzhalter (`$1`, `$V1`, …),
exakter Fehlercode-Vergleich, Suite-Doppellauf-Determinismus.

Entsteht in JOB-002 — nach dem Shared Kernel (JOB-001).
