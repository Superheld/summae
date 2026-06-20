# Architektur — das Denkmodell von summae (sprachneutral)

Wie summae **gedacht** ist, unabhängig von der Sprache. Gilt für jede
Implementierung (PHP, Node, …) und für jeden, der ein Pack oder eine Operation
baut. Sprachspezifische Pfade/Packages stehen in `implementations/<sprache>/docs/`.

> Normative Tiefe liegt in der Wissensbasis (`40-domaenenmodell/jurisdiction-profil.md`,
> `context-map.md`). Die wird **nicht** mit ausgeliefert — darum trägt
> dieses Dokument das Modell self-contained im Repo.

## Der Stapel

```
┌─ Konfiguration / Manifest + Resolver ───────────────┐  Pack auswählen/komponieren;
│  pack:"de-complete" | eigene Modulliste | überschr.   │  Resolver prüft Kohärenz
├─ Pack (= Jurisdiction Profile = "Regelmodul") ───────┤  Jurisdiktions-Bündel
│  Kontenrahmen, Steuerschlüssel, Mappings, AfA-Tabellen,│  ("tzdata fürs
│  Rundungspolitik, Export-Adapter … — fast alles Daten │   Rechnungswesen")
├─ Drei Politiksorten über dem Substrat ───────────────┤  Constraint · Projektion
│  Mechanik im Kern, Inhalte aus dem Pack               │  · Expansion
├─ Substrat (der Kern) ────────────────────────────────┤  kennt kein Gesetz
│  Buchung, Konto, Journal, Saldo, Periode,             │
│  post/settle/reverse, allocate                        │
└────────────────────────────────────────────────────────┘
```

## 1. Substrat (der Kern)

Jurisdiktionsfrei: Buchung, Konto, Journal, Saldo, Periode, Festschreibung, Storno,
offener Posten, Dimension — plus die *Mechanik* `post`, Journal-Append, Saldo-Faltung,
`sequenceNumber`-Vergabe, `correct`. Kein Paragraph, kein Steuersatz, kein Kontenrahmen.
Ergäbe ein Konzept auch für eine *fiktive* Jurisdiktion Sinn → es gehört hierher.

## 2. Drei Politiksorten (alles über dem Substrat ist genau eine davon)

- **Constraint** — Prädikat, das gelten muss, durchgesetzt beim Schreiben:
  Σ Soll = Σ Haben · Belegpflicht · Periode offen · Festschreibung unveränderbar ·
  Belegpflichtfelder (z. B. USt-IdNr. bei innergem. Lieferung) · Journalnummer-Lückenlosigkeit.
- **Projektion** — Journal → Sicht (nie aus gespeicherten Salden): `trialBalance`/SuSa,
  `balanceSheet`, `incomeStatement`, `cashBasisReport`/EÜR, `vatReturn`, `ecSalesList`/ZM,
  `openItems`, `assetRegister`, `auditLog`, `costAllocationSheet`/BAB, `journalExport`/Z3,
  `datevExport`. Mechanik im Kern, **Mapping** aus dem Pack.
- **Expansion** — Absicht → ausbalancierte Buchungen: `expandTax`, `postVoucher`,
  `settle` mit Differenz (Skonto/§ 17), `runDepreciation` (AfA), GWG-Weiche bei
  `acquireAsset`, `disposeAsset`, `reverse` (Generalumkehr), Costing-Umlagen. Sockel im
  Kern, **Stecker** (Regeldaten) aus dem Pack.

**Ermessens-Grenze:** Modulfähig (Expansion) ist nur, was *deterministisch aus
Regeldaten + Buchungsbestand* ableitbar ist (AfA = tabellengetrieben). Bewertungs-Ermessen
(Rückstellungshöhe, Forderungsabwertung, Niederstwerttest) ist **App-Sache** — die App
darf eine Ermessensgröße als Parameter in eine Expansion reichen, das Urteil selbst nie.

## 3. Pack (= Jurisdiction Profile = „Regelmodul")

Das versionierte Bündel aller Daten+Regeln einer Jurisdiktion. „Deutschland" ist das
*erste* Pack, nicht die eingebaute Annahme. **Fast alles ist Daten** — echten *Code*
braucht ein Pack an genau zwei Stellen: ein neues Steuer-*Paradigma* (US-Sales-Tax hat
keinen Vorsteuerabzug → anderer Algorithmus) und je ein dünner Export-Serializer
(DATEV / SAF-T / FEC …).

Begriffsklärung (drei Fassungen *desselben* Gedankens, zunehmend scharf):
Drei-Schichten (Kern/Regelmodule/App) → Substrat + Politiksorten → **Pack**.
„Regelmodul" und „Pack" sind synonym.

## 4. Konfiguration: Modul / Manifest / Resolver

Ein Pack ist **kein Monolith**, sondern selbst eine Komposition.

- **Modul** = adressierbare Einheit, Granularität *kohärenter Regelsatz* (ein Kontenrahmen,
  ein Steuerschlüssel-Satz, ein Mapping, ein AfA-Regelsatz, eine Rundungspolitik).
  Deklariert, *was es beiträgt* und *wovon es abhängt*.
- **Pack** = benannte, aufgelöste Modulliste (Manifest). `de-complete` ist *ein
  kuratiertes* Manifest, nicht der einzige Weg.
- **Drei Nutzungswege, ein Mechanismus:** (1) kuratiert nehmen · (2) kuratiert +
  überschreiben/weglassen · (3) selbst à la carte komponieren.
- **Resolver** prüft Abhängigkeiten + referentielle Integrität (bucht ein Steuerschlüssel
  auf ein Konto, das der Kontenrahmen nicht hat? braucht eine Projektion ein `taxTag`, das
  kein Modul erzeugt?) und **scheitert laut** (`E_PACK_UNRESOLVED_REF` / `E_PACK_INCOHERENT`)
  statt still falsch zu rechnen.

## Baustatus — ehrlich (wichtig!)

Das meiste hiervon ist **Konzept festgehalten, Umsetzung nachfragegetrieben** — nicht
fertiger Code:

- ✅ **DE-Pack läuft ohne Kernänderung** — durch die PHP-Referenz faktisch belegt.
- ❌ **Fiktives Test-Pack** (3-Nachkomma-Währung, Rundung je Position, kein Vorsteuerabzug)
  noch **nicht** als Fixture gebaut. Bis es grün läuft, ist die Jurisdiktionsfreiheit
  *behauptet, nicht erzwungen* — bewusste, festgehaltene Lücke.
- ❌ **Modul-Registry, Resolver, `E_PACK_*`-Codes, Manifest-Format** — geplant, nicht gebaut.
- Heute steht im Datenformat de facto *ein* DE-Pack; die Profil-Policy-Felder
  (Rundungsmodus, Skala) sind bewusst auf eine spätere Stufe verschoben.

Das **fiktive Test-Pack ist selbst ein Konformitätstest** und fällt damit unter die
oberste Qualitätsrichtlinie (`CLAUDE.md`: „jede künftige Jurisdiktion") — der Naht-Beweis
der Entkopplung gehört in dieselbe sprachneutrale Suite.
