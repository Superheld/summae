# Fixtures-Gruppe `de-composed-equals-de` — „DE komponiert == DE heute"

> **ENTWURF, nicht normativ.** Gate-1-Plan des Bau-Flow-Laufs #1 (`80-implementierung/PACK-KOMPOSITION.md`,
> Gate 1: „DE komponiert == DE heute … geprüft gegen die bestehenden 45 Fixtures, byte-identisch.
> Das Regressions-Orakel."). Quellen: `_bauflow-pack-gate01/design/module-manifest-resolver.md` (§ 4,
> ResolvedPack → hand-gereichte Daten) + `_bauflow-pack-gate01/spec/api.additions.md` (`resolvePack`,
> Pinning). Geht **erst mit menschlicher Freigabe** nach `70-testsuite/`; dort liegt vorerht **nichts**.

## Das Problem und die Entscheidung: 45 Fixtures NICHT neu schreiben

Das Regressions-Orakel verlangt, dass das aus Modulen zusammengesetzte DE-Pack das heutige DE-Verhalten
**byte-identisch** reproduziert. Der naive Weg — alle 45 Fixtures auf `setup.pack` umschreiben — ist aus
drei Gründen falsch:

1. **Append-only-Invariante** (`CLAUDE.md`, testsuite): Fixtures werden nie still editiert. 45 Umschreibungen
   wären 45 stille Verhaltens-Neudefinitionen am Vertrag.
2. **Redundanz ohne Beweiskraft:** Eine umgeschriebene Fixture beweist nicht mehr als die Kombination aus
   (a) „der Resolver liefert das richtige Bündel" + (b) „die Engine verarbeitet ein Bündel gleich, egal
   woher es kommt". Genau diese zwei Aussagen isolieren die drei Fixtures dieser Gruppe.
3. **Wartung:** Die 45 bleiben die kanonische Verhaltensquelle; die Pack-Schicht hängt sich **davor**, nicht
   **hinein**.

## Der Mechanismus: Transitivität statt Vervielfachung

Die Gleichheit „DE komponiert == DE heute" wird über **zwei isolierte Naht-Beweise** + eine **Transitivitäts-Brücke**
gezeigt — drei Fixtures hier, plus eine kleine, lokale Erweiterung des sprachneutralen Runner-Kontrakts (siehe
unten), **keine** Änderung an den 45.

> **Namens-Disziplin (Pinning-Eindeutigkeit):** Die Manifeste dieser Gruppe heißen
> **`de-mini-regression`** (3-Modul-Mini-Basis) bzw. `de-mini-regression-plus` (Override-Probe) —
> **nicht** `de-complete`. `de-complete`+`2026.1` bezeichnet exklusiv die kanonische **9-Modul-Liste**
> (design § 2 / datenformat.additions § E); die Mini-Basis hier trägt nur genau die Stammdaten aus
> `core/create-tenant-profile` (Kontenrahmen, `taxCodes`, `packPolicy`). Gleiche `id`+`version` für zwei
> verschiedene Inhalte würde das Pinning brechen.

- **Pivot-Beweis** — `resolve-de-complete-equals-handfed.json`: `resolvePack(de-mini-regression)` ergibt einen
  `ResolvedPack`, der **byte-identisch** den hand-gereichten DE-Regelmodul-Daten (`ruleModules`) entspricht,
  die die 45 Fixtures heute inline tragen (Kontenrahmen, `taxCodes`, `packPolicy`). Das ist Aussage (a).
  Formal: `resolve(de-mini-regression) ≡ H` (H = hand-gereichtes Bündel).
- **Substitutions-Beweis** — `tenant-from-de-complete-posts-identically.json`: derselbe Mandant wie
  `core/create-tenant-profile`, aber via `setup.pack` (Manifest → aufgelöst → in `createTenant`) statt inline.
  Buchung + `trialBalance` sind byte-identisch. Das ist Aussage (b): die Engine ist gegenüber der **Herkunft**
  des Bündels invariant — `engine(H) ≡ engine(resolve(de-mini-regression))`.
- **Override-Beweis** — `de-complete-override-remove-equals-base.json`: Nutzungsweg 2 („kuratiert + anpassen")
  landet via `overrides[remove]` wieder exakt auf der Mini-Basis. Sichert, dass die Kompositions-Freiheit das
  Ergebnis nicht still verschiebt.

**Transitivität (die Brücke ohne 45 Umschreibungen):** Wenn `resolve(de-mini-regression) ≡ H` (Pivot) **und** der
Runner ein `ResolvedPack` byte-identisch wie hand-gereichte `ruleModules` in den Mandanten reicht (Substitution),
dann liefert **jede** der 45 Fixtures, ausgeführt gegen den aufgelösten Pack statt gegen ihr inline-H, per
Konstruktion byte-identische Ergebnisse — ohne dass eine einzige Datei kopiert wird. A == Erwartung und
B == A ⇒ B == Erwartung (dasselbe Orakel-Prinzip wie die sprachübergreifende Äquivalenz, `CLAUDE.md`).

## Was Gate 2/3 daraus operativ macht (für den Runner, sprachneutral)

Die Transitivität wird im Runner zu **einem zusätzlichen, opt-in Suite-Doppellauf** — der „Pack-Modus":

1. **Normallauf** (heute): 45 Fixtures gegen ihr jeweiliges inline-`ruleModules`. Unverändert.
2. **Pack-Modus** (neu, nur für die DE-anwendbaren Fixtures): Der Runner ersetzt das inline-`ruleModules`
   einer Fixture durch `resolve(<DE-Manifest>, moduleSource)` und führt dieselben `steps`/`projections` gegen
   dieselben `expect`-Werte aus. Grün ⇔ byte-identisch. Das ist der mechanische Einlöser von „45 byte-identisch",
   ohne 45 neue Dateien. (In dieser Gruppe trägt das `de-mini-regression`-Manifest die Mini-Basis; über alle 45
   Fixtures generalisiert es das kanonische 9-Modul-`de-complete` — Gate-2-Material.)

Die drei Fixtures dieser Gruppe sind die **explizite, fixierte** Form der zwei Brücken-Aussagen (a)+(b)+Override;
der Pack-Modus ist ihre **Verallgemeinerung über die 45**. Voraussetzung: `de-complete` muss als vollständiges
Manifest (alle in den 45 referenzierten Konten/taxCodes/Mappings/Assets) vorliegen — die Module hier sind die
**Mini-Regressionsbasis** (genau die Stammdaten aus `core/create-tenant-profile`); das vollständige `de-complete`
mit SKR-Voll-Kontenrahmen + allen Mappings ist Gate-2-Material (`modules/` + `packs/de-complete.json` in der WB).

## Format-Notizen (für Gate 0 / Runner-Kontrakt-Erweiterung)

- **`setup.moduleSource`** (neu): der abstrakte Modulbestand, gegen den `resolvePack` auflöst (Design:
  `moduleSource` als Fake-Quelle, `module-manifest-resolver.md` § 3). Trägt `modules[]` im Modul-Schema (§ 1).
- **`setup.manifests`** (neu): die im Test referenzierbaren Manifeste (Manifest-Schema, § 2).
- **`setup.pack`** (neu): Mandanten-Pinning auf ein Manifest statt inline-`ruleModules` — der Gate-4-Pfad
  (`api.additions.md` A.1). `createTenant.input.pack` löst beim Aufbau einmal auf und pinnt.
- **`op: resolvePack`** (neu): reine Auflösungs-Operation, Ergebnis = `ResolvedPack` (Teilmengen-Vergleich
  wie bei allen `result`-`expect`).
- **`covers`**: `SF-27` (neuer Standardfall „Pack aus Modulen komponieren == hand-gereicht") + `F-PACK-00x`
  als vorläufige Feature-IDs — bei Freigabe in `30-anforderungen/` zu vergeben.

## Bewusst NICHT hier

- **Resolver-Fehlerfälle** (`E_PACK_UNRESOLVED_REF`/`E_PACK_INCOHERENT`/`E_POLICY_INVALID`) — eigene Gruppe
  (PACK-KOMPOSITION.md Gate 1, Fehlercode-Tabelle § 3.5). Diese Gruppe prüft nur den **Erfolgs**-Pfad „== DE".
- **Test-Pack XX** (perLine/Skala 3/kein Vorsteuerabzug) — eigene Gruppe `conformance-xx/`.
- **Vollständiges `de-complete`** mit SKR03/04-Voll-Kontenrahmen + allen DE-Mappings — Gate 2 (`modules/`,
  `packs/`). Hier steht die **Mini-Basis**, die das Mechanismus-Orakel trägt.
