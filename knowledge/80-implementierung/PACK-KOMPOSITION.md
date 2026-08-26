# Pack-Komposition — Bau-Flow-Lauf #1

Der erste durchgespielte Lauf des [`METHODE-Bauflow.md`](../METHODE-Bauflow.md). Baut die
**komponierbaren Packs** (Modul / Manifest / Resolver) aus `40-domaenenmodell/jurisdiction-profil.md`
— direkt als Komposition, inside-out, geldwürdig streng. Design-Quellen: `jurisdiction-profil.md`
(Modul/Manifest/Resolver, Rosetta, Politiksorten-Zensus) + `00-projekt/entwurf-pack-policy-testpack-2026-06-09.md`
(packPolicy-Felder, Schema-Lockerung, Test-Pack „XX" + Fixtures). Entscheidungen: `entscheidungen.md` 2026-06-20.

## Festgelegt (Gate-0-Vorgaben)

- **Heimat = Wissensbasis + Sync.** Kanonisch hier, gespiegelt in jede Runtime (wie die Fixtures), Release-Build bündelt mit:
  ```
  modules/                  ← Bibliothek, nach Bestandteils-TYP (nicht nach Politiksorte)
    accounts/summae-base.json (mitgelieferter Default) · skr03.json · skr04.json (via Import)
    tax/de-ust-2026.json
    mappings/de-hgb-bilanz-266.json · euer-anlage.json · vatreturn-de.json
    depreciation/afa-tables-de.json
    policy/de-eur.json
  packs/
    de-complete.json        ← Manifest: benannte, aufgelöste Modulliste
  testing/testsuite/fixtures/conformance-xx/   ← Test-Pack „XX" (test-only, nicht ausgeliefert)
  ```
  Seit 2026-08-26 im Repo selbst gepflegt (`summae/pack-library/`); der Spiegel WB→Runtime und sein Skript sind entfallen.
- **packPolicy E-A/E-B/E-C: alle nach Empfehlung** — Manifest-Kopie ja, Schema-`$id` → **0.6**, Enum `perVoucher|perLine`.
- **Modul** = kohärenter Regelsatz (ein Kontenrahmen, ein Steuersatz-Satz, *ein* Mapping, ein AfA-Satz, eine Policy). Deklariert **was es beiträgt** (Politiksorten-Beiträge) und **wovon es abhängt**.
- **Manifest** = `de-complete` u. a.: kuratierte Modulliste + Overrides + `packPolicy`.

## Der Lauf entlang der Gates

### Gate 0 — VERTRAG (Spec, sprachneutral, vor Code)
In `50-spezifikation/` überführen:
- `datenformat.md`: **Modul-** und **Manifest-Format** (neu); `packPolicy` am Profil + Manifest-Kopie (E-A); amount-Pattern `^-?\d+(\.\d{1,4})?$` (E-B); `$id` → **0.6**, `formatVersion` mitziehen.
- `fehlerkatalog.md`: `E_PACK_UNRESOLVED_REF`, `E_PACK_INCOHERENT`, `E_POLICY_INVALID`, `E_AMOUNT_SCALE_MISMATCH`.
- `determinismus.md` §2: Enum-Umbenennung `per_document|per_line` → `perVoucher|perLine` (E-C; reine Benennung).
- **Resolver-Semantik** (neuer api.md-Abschnitt): Auflösungsreihenfolge (Abhängigkeiten), referentielle-Integritäts-Prüfungen, Override-Semantik, **fail-loud**.

**Gate:** Vertrag steht; keine offenen Findings; Entscheidungen geloggt (erledigt).

### Gate 1 — ORAKEL (Fixtures, `testing/testsuite/`)
- **Resolver-Fehlerfälle** je eigener Fixture: fehlende Konto-Referenz, Zyklus, kollidierender Override, Steuerschlüssel→nicht-existentes Konto, Mapping→fehlende Konten, Projektion braucht `taxTag`, das kein Modul erzeugt → erwarteter `E_PACK_*`.
- **„DE komponiert == DE heute":** ein `de-complete`-Manifest, das das heutige DE-Verhalten aus Modulen zusammensetzt — geprüft gegen die **bestehenden 45 Fixtures**, byte-identisch. Das Regressions-Orakel.
- **Test-Pack „XX"** als *komponiertes* (nicht monolithisches) Pack: die 4 XX-Fixtures aus dem Entwurf (`conformance-xx/`), + packPolicy-Fälle (currencyScale 3, perLine).

**Gate:** `validate.py` grün; jeder Fehlercode + jeder Fall hat eine Fixture; `abdeckung.md` aktualisiert.

### Gate 2 — KERN inside-out (PHP-Referenz zuerst)
- **Ring 0 — reiner Resolver:** `Module`/`Manifest`/`ResolvedPack` (Value Objects) + Resolver-Algorithmus, gegen **Fakes inkl. bewusst kaputter Module**. Jeder Grenzfall → lautes `E_PACK_*`. Hier sitzt die Geld-Strenge.
- **Ring 1 — Engine + Regressions-Gate:** Der `ResolvedPack` erzeugt genau die Struktur, die heute als hand-gereichte Regelmodul-Daten in den Mandanten geht (`TaxProfile`, Mappings, Konten, `assetAccounts`, `packPolicy`). Gate: **DE komponiert == DE heute** (45 Fixtures grün).
- **Ring 2 — Lade-Adapter:** Module/Manifeste als JSON von der Platte; Fake-Quelle → echter Loader.

**Gate:** Unit/Fake-Tests für *jeden* Edge/Error grün; Invarianten gehalten.

### Gate 3 — GRÜN (PHP)
PHPStan max + PHPUnit + Konformität `--strict` + Doppellauf, In-Memory **und** Datenbank.

### Gate 4 — ADAPTER & OBERFLÄCHE
Pack-Auflösung in den Mandanten-Aufbau verdrahtet (der Mandant **pinnt eine Pack-Version**, Upgrade explizit — wie heute das Profil-Pinning). CLI: `summae.json` referenziert ein Manifest, lädt/auflöst beim Start.

### Gate 5 — ÄQUIVALENZ (Cross-Test)
Resolver nach **Node** portiert; Cross-Test: derselbe Resolver urteilt **byte-gleich** in beiden Runtimes; das komponierte DE-Pack + XX laufen in beiden. SF-15-Muster, beide Richtungen.

### Gate 6 — DOKU & ABDECKUNG
`docs/handbuch`: „ein eigenes Pack schnüren" (die drei Nutzungswege). `glossar` (Modul/Manifest/Resolver — größtenteils via `jurisdiction-profil.md` schon da). `abdeckung.md`, `architektur`-Annotation, Entscheidungslog.

### Querliegend — REVIEW
Adversarial: Korrektheit, **Geld/Rundung/Skala**, Äquivalenz, Resolver-Sicherheit (kann ein bösartiges Manifest still falsch auflösen?). Fertig ist nach dem Review.

## Bewusst gestaffelt / später
- **Zweites reales Pack** (AT/FR), US-Scope, SAF-T/FEC-Adapter — `40-domaenenmodell/offene-fragen.md`. XX beweist die Naht, nicht einen Markt.
- **GJ-/Kalenderkonvention als Policy-Feld** — erst mit einem realen Pack abweichender Konvention.
- Multi-Currency-Buchung bleibt v2.

> Abnahme (Konformitätsanspruch erzwungen, nicht behauptet): Alle Fixtures — DE komponiert *und* XX — laufen gegen den **unveränderten** Kern, nur Pack-Daten + `packPolicy`; Resolver byte-gleich PHP↔Node.
