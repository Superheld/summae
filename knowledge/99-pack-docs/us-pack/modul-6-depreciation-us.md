# Modul 6 — Depreciation USA (`depreciation`)

```
kind: depreciation · id: macrs-us · version: 2026.1 · formatVersion: 0.6
contributes: ["depreciation"] · dependsOn: []   (datenrein, keine Konten-Referenz)
data = De-minimis-Grenzen + Recovery-Periods (assets-modell.md v0.4)
```

## Zweck

Reine **Daten** zur Abschreibung: De-minimis-Sofortabzug, MACRS-Recovery-Periods als
Nutzungsdauern. Die **Mechanik** (linear über Nutzungsdauer, pro-rata monatsgenau) ist
**Kern**, nicht hier.

## Abweichung zu DE (AfA/GWG)

| DE | US |
|---|---|
| GWG-Sofortabzug 800 € (§6 Abs.2 EStG) | **De-minimis Safe Harbor** 2.500 USD (5.000 mit AFS) |
| GWG-Sammelposten 250,01–1.000 € (Pool) | **kein Pool** — `poolMin`/`poolMax` = null |
| Nutzungsdauern aus AfA-Tabellen | **MACRS-GDS-Recovery-Periods** |
| degressive AfA „Investitionsbooster" | **100 % Bonus** + **§179** (Wahlrechte, s. u.) |

## De-minimis Safe Harbor (Treas. Reg. §1.263(a)-1(f), Rechtsstand 2026)

```
gwgThresholds: [
  { validFrom: "2016-01-01", validTo: null,
    immediateMax: "2500.00",   // Sofortabzug bis 2.500 USD/Beleg ohne AFS
    poolMin:      null,        // kein Sammelposten-Konzept in den USA
    poolMax:      null }
]
```

Weiche: Anschaffung ≤ 2.500 USD je Beleg/Stück → Sofortabzug (Konto **6510**); darüber →
Aktivierung + planmäßige Abschreibung. Mit geprüftem Abschluss (Applicable Financial
Statement, AFS) liegt die Grenze bei **5.000 USD** — als zweite, AFS-bedingte Variante eine
Build-Option (`offene-entscheidungen.md`). Das Feld heißt aus Format-Gründen weiter
`gwgThresholds`/`immediateMax` (geteilter Vertrag mit DE), trägt hier aber die US-Semantik.

## MACRS-Recovery-Periods als Nutzungsdauer (GDS, überschreibbar)

`usefulLife[]` mit `months`; im Anlagegut überschreibbar. MACRS General Depreciation System:

| assetClass | MACRS GDS | months |
|---|---|---|
| it-hardware | 5 Jahre | 60 |
| vehicles | 5 Jahre | 60 |
| office-furniture | 7 Jahre | 84 |
| machinery | 7 Jahre | 84 |
| land-improvements | 15 Jahre | 180 |
| residential-real-estate | 27,5 Jahre | 330 |
| nonresidential-real-estate | 39 Jahre | 468 |

## Bewusst außerhalb des aktuellen Engine-Umfangs

- **Echte MACRS-Beschleunigung.** Die Engine schreibt **linear** ab (`allocateEvenly`); die
  echten MACRS-Sätze (200 %/150 % degressiv, **Half-Year-/Mid-Quarter-/Mid-Month-Konvention**)
  sind hier als gerade Nutzungsdauern hinterlegt (Buch-/GAAP-Sicht). Eine MACRS-Tabellen-Engine
  wäre Kern-Erweiterung, nicht Pack-Daten.
- **Section 179** (2026: Höchstabzug **2.560.000 USD**, Phase-out ab **4.090.000 USD**,
  inflationsindexiert) und **100 % Bonus Depreciation** (durch den *One Big Beautiful Bill Act*
  2025 dauerhaft, für nach dem 19.01.2025 in Betrieb genommene Wirtschaftsgüter) sind
  **Steuer-Wahlrechte** — hier als Normwissen dokumentiert, **nicht** als Buchungsautomatik.

## Hinweis

Konten kommen **nicht** aus diesem Modul, sondern aus **Modul 7** (`assetAccounts`) — die
Trennung ist der Format-Vertrag (`depreciation` = Sätze/Daten, `assetAccounts` = Bewegungskonten).
