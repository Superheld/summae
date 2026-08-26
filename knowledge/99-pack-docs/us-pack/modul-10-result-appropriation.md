# Modul 10 — Closing the Income Summary (US) (`resultAppropriation`)

```
kind: resultAppropriation · id: us-appropriation · version: 2026.1 · formatVersion: 0.6
contributes: ["resultAppropriation"] · dependsOn: [{kind: accounts, id: us-accounts-2026}]
data = allocationAccount + targets{} (je Ziel ein Konto)
```

## Zweck

Where a resolved result is booked. Unlike the German case, closing the books into retained
earnings is **not** a resolution under US practice — it is part of the closing process, and what
*is* resolved is the dividend. The pack therefore offers exactly one target: `carryForward`.

Silence about `distribution` is a deliberate answer, not a gap: a dividend is a liability
created by a separate decision and booked as an ordinary entry, not an appropriation target.

## Verwendungskonto

`allocationAccount: 3300` — das Konto mit subtype `result_allocation`. Sein Saldo ist
das, was die Bilanzposition mit `includesNetIncome` vom kumulierten Ergebnis abzieht; deshalb muss es
**in genau dieser Position** gemappt sein und in keiner anderen (`PackCompletenessTest` prüft das).

## Ziele

| Ziel (`target`) | Konto | Label im Modul |
|---|---|---|
| `carryForward` | 3100 | Retained Earnings |

> Ein Ziel, das hier nicht steht, ist `E_APPROPRIATION_UNSUPPORTED` — und der Fehler nennt die
> angebotenen, damit ein Formular sie auflisten kann statt zu raten. `tenantConfiguration` meldet
> dieselbe Liste als `appropriationTargets[]`.
