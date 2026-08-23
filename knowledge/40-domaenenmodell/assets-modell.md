# Assets — taktisches Modell

**Kontext: Assets / Anlagen (Supporting).** Stand 2026-06-07. Sprache: Anlagegut, Nutzungsdauer, AfA, Restbuchwert. Klassisches Nebenbuch: führt das Anlageverzeichnis, erzeugt AfA-Buchungen konform zur Published Language.

## Aggregate

### `Asset` (Anlagegut)

- **Stammdaten:** Bezeichnung, Anlagekonto-Ref, Zugangsdatum, Anschaffungs-/Herstellungskosten (Money), Zugangs-Buchungsref (Verknüpfung zum Kauf, GoBD-Rückverfolgbarkeit).
- **AfA-Plan** (intern): Methode (linear; degressiv/Pool als Regelmodul-Erweiterung), Nutzungsdauer (Vorschlag aus AfA-Tabellen = Regelmodul-Daten, überschreibbar), Pro-rata-Beginn (monatsgenau, vgl. EÜR-Beweis TC4: 6/36).
- **Lebenslauf** (intern, Entities): Zugang → planmäßige AfA je Periode → ggf. Sonderereignisse (Teilabgang, außerplanmäßige Abschreibung, Zuschreibung) → Abgang (Verkauf/Entnahme/Verschrottung mit Restbuchwert-Ausbuchung und ggf. Veräußerungsergebnis).
- **Invarianten:** Restbuchwert = AHK − Σ Abschreibungen (± Zu-/Abgänge), nie < 0 (Erinnerungswert 1 € als Regelmodul-Option); jeder Lebenslauf-Schritt referenziert seine Journal-Buchung; keine AfA in Perioden vor Zugang oder nach Abgang — **Ausnahme: der Sammelposten, wenn das Pack ihn beim Abgang nicht vermindert** (s. u.). Der Abgang bucht die bis dahin fällige AfA nach, bevor er ausbucht; sonst fehlte die AfA der letzten Monate dauerhaft (der Lauf überspringt abgegangene Güter) und der Aufwand erschiene als überhöhter Abgangsverlust statt als Abschreibung.
- **GWG-Weiche:** Beim Zugang entscheidet das Regelmodul (Grenzen mit Gültigkeit: 800 € / Sammelposten 250–1.000 €) über Sofortabzug, Pool oder Aktivierung — die *Mechanik* (drei Pfade) ist Assets-Kern, die *Grenzen* sind Daten. SF-05.

### `AssetPool` (Sammelposten, § 6 Abs. 2a EStG)

- Jahrgangsbezogen; die Auflösung läuft starr über die im Pack deklarierte Dauer (`poolYears`) — **nicht** über eine im Kern verdrahtete Zahl.
- **Ob ein Abgang den Pool vermindert, entscheidet das Pack** (`poolReducedOnDisposal`), nicht dieses Modell. Deutschland sagt nein (§ 6 Abs. 2a Satz 4 EStG: der Sammelposten wird nicht vermindert, die Rate läuft bis zum Ende weiter); **UK und Australien entnehmen Abgänge ihren Pools**. Beides sind gültige Pool-Regime, also ist keins von beiden Invariante.
- Wer eine Pool-Spanne aufmacht (`poolMin`/`poolMax`), muss **beide** Antworten geben — `poolYears` und `poolReducedOnDisposal`. Das Schema verlangt sie bedingt, der Kern verweigert statt zu raten. Vorher stand hier „starr über 5 Jahre, unabhängig von Abgängen" als *Invariante*: beides war deutsches Recht, und beides hätte jede andere Jurisdiktion still geerbt (SPEC-004 für die Dauer, IMPL-025 für den Abgang).

## Kontenzuordnung (v0.5/SPEC-004 — war unspezifiziert)

Asset-Buchungen (Zugang, AfA-Lauf, GWG-Sofortabzug, Abgang) brauchen Konten, die **nicht** aus dem Anlagegut selbst folgen. Sie kommen aus dem Regelmodul (Profil-Bestandteil), nicht aus einer Namens-Heuristik:

| Schlüssel | Verwendung |
|---|---|
| `acquisitionCounterAccount` | Gegenkonto beim Zugang (i. d. R. Geldkonto/Verbindlichkeit; oder per Beleg übergeben) |
| `depreciationExpenseAccount` | AfA-Aufwandskonto |
| `gwgExpenseAccount` | GWG-Sofortabschreibung |
| `disposalProceedsAccount` / `disposalLossAccount` | Veräußerungsergebnis beim Abgang |

Pro Anlageklasse überschreibbar. Das `assetAccount` (Bilanzkonto) bleibt am Anlagegut. Die `acquireAsset`-Operation darf das Gegenkonto auch explizit erhalten (Vorrang vor Regelmodul).

## Domain Services

- **`runDepreciation`** (AfA-Lauf): erzeugt für eine Periode die AfA-Buchungen aller Anlagen als normale Journal-Buchungen (kein Sonderweg). Idempotent je Periode (Wiederholung erzeugt keine Doubletten). Side-effect: `post` im Ledger via Anwendungsschicht.
- **`assetRegister`** (Anlageverzeichnis): Projektion — Pflicht auch bei EÜR (§ 4 Abs. 3 S. 5 EStG).

## Domain Events

`AssetAcquired` · `AssetDepreciated` (je Lauf) · `AssetDisposed` · `AssetWrittenDown` (außerplanmäßig)

## v0.4: AfA-Methoden (StB-4, Rechtsstand 06/2026 verifiziert)

- **Degressive AfA ist aktiv** (Investitionsbooster: Anschaffung 01.07.2025–31.12.2027, max. 2,5× linear, Deckel 30 %/Jahr) → v1-Mechanik: AfA-Plan-Methoden `linear` und `declining`; **automatischer Methodenwechsel declining → linear**, sobald der lineare Restwert-Satz (Restbuchwert / Restlaufzeit) höher ist — Kern-Mechanik, Sätze und Anschaffungszeiträume sind Regelmodul-Daten mit Gültigkeit.
- **§ 7g:** Sonder-AfA (bis 40 %, Gewinngrenze 200.000 € steuerlicher Gewinn — BFH 01.10.2025) und AK-Minderung bei IAB-Inanspruchnahme sind Plan-Mechanik (Kern). Der **IAB selbst ist außerbilanziell** → Steuerermittlung, App-/StB-Sache (Abgrenzungsprinzip). Die Gewinngrenzen-*Prüfung* ist ebenfalls App-Sache (braucht außerbilanzielle Korrekturen).
- HB/StB-AfA-Differenzen: bleibt bei der v0.3-Entscheidung (ein Bewertungsbereich, `valuationArea` reserviert).
