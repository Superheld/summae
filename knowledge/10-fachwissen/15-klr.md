# KLR — Kosten- und Leistungsrechnung

## Zweck und Abgrenzung

Internes Rechnungswesen: Selbstkosten kalkulieren, Preisuntergrenzen bestimmen, Wirtschaftlichkeit steuern. **Keine gesetzliche Form** (Ausnahme: öffentliche Aufträge nach LSP/VO PR 30/53, Krankenhaus-KLR u. ä.) — die KLR folgt betriebswirtschaftlicher Zweckmäßigkeit, nicht dem HGB.

Zentrale Begriffsabgrenzung (Quelle vieler Modellfehler):

| Extern (Doppik) | Intern (KLR) | Unterschied |
|---|---|---|
| Aufwand | Kosten | Kosten = betriebsbedingter, *bewerteter* Verzehr. Neutraler Aufwand (betriebsfremd, periodenfremd, außerordentlich) ist keine Kosten; **kalkulatorische Kosten** (Unternehmerlohn, kalk. Zinsen, kalk. Miete, kalk. AfA, kalk. Wagnisse) sind Kosten ohne (gleichhohen) Aufwand |
| Ertrag | Leistung | analog |

→ „Aufwand" und „Kosten" sind **verschiedene Konzepte in verschiedenen Kontexten**, nicht Synonyme. Die Überleitung (Abgrenzungsrechnung) ist eine explizite Rechnung.

## Drei Stufen

1. **Kostenartenrechnung** — *Welche* Kosten? Gliederung nach Art (Personal, Material, AfA, …). Anknüpfung an die Finanzbuchhaltung + Abgrenzung + kalkulatorische Kosten. Unterscheidungen: Einzel-/Gemeinkosten (direkt zurechenbar?), variabel/fix (beschäftigungsabhängig?).
2. **Kostenstellenrechnung** — *Wo* entstanden? Kostenstellen (Organisationseinheiten/Verantwortungsbereiche). Instrument: **BAB** (Betriebsabrechnungsbogen): Primärkosten verteilen, **innerbetriebliche Leistungsverrechnung** (Umlage von Hilfs- auf Hauptkostenstellen; Verfahren: Anbau-, Stufenleiter-, Gleichungsverfahren), Kalkulationssätze bilden (Zuschlagssätze).
3. **Kostenträgerrechnung** — *Wofür*? Produkte/Aufträge. **Kalkulation** (Zuschlagskalkulation, Divisionskalkulation, Äquivalenzziffern, Maschinenstundensatz) und **Kostenträgerzeitrechnung** (kurzfristige Erfolgsrechnung, Gesamt-/Umsatzkostenverfahren).

## Systeme

- **Istkosten / Normalkosten / Plankosten** (nach Zeitbezug); Plankostenrechnung mit Abweichungsanalyse (Beschäftigungs-, Verbrauchs-, Preisabweichung).
- **Vollkosten vs. Teilkosten:** Vollkostenrechnung verteilt alles (Gefahr: Fixkostenproportionalisierung); **Deckungsbeitragsrechnung** (DB = Erlös − variable Kosten; ein-/mehrstufige Fixkostendeckungsrechnung) für Entscheidungen (Preisuntergrenze, Sortiment, Make-or-buy, Break-even).
- Moderne Verfahren (Prozesskostenrechnung, Target Costing) — vormerken, nicht Kernscope.

## Verhältnis zu Fibu und kommunaler Doppik

Die KLR *konsumiert* Buchungsdaten der Finanzbuchhaltung (Primärkosten) und ergänzt eigene Verrechnungen (sekundäre Buchungen: Umlagen, kalkulatorische Kosten). Sie braucht eigene Dimensionen (Kostenart ← oft = Konto, Kostenstelle, Kostenträger). In der kommunalen Doppik ist KLR teils vorgeschrieben (Gebührenkalkulation! — kostendeckende Gebühren nach KAG) und nutzt die Produktstruktur.

---

## Konsequenzen für die Packages

- KLR ist ein **eigener Bounded Context**: eigene Sprache (Kosten ≠ Aufwand), eigene Buchungen (Umlagen, kalkulatorische Kosten), die *nicht* ins Fibu-Journal gehören.
- Schnittstelle Fibu → KLR: definierter Datenfluss (Buchungen mit Kostenstellen-/Kostenträger-Tags oder Export/Import) — Context-Mapping-Entscheidung in Phase 2.
- Buchungs-Dimensionen (Kostenstelle, Kostenträger) müssen schon im Fibu-Kern erfassbar sein (Kontierung am Beleg), auch wenn die Verarbeitung im KLR-Kontext stattfindet.
- BAB, Umlageverfahren, Kalkulationsschemata sind Berechnungs-Engines über den Dimensionen — gut testbar, sprachneutral spezifizierbar.

## Offene Fragen — Stand 2026-06-07

- Scope-Schnitt: **beantwortet** — KLR in der ersten Generation (Bruce); Modell + Fixtures existieren (`costing-modell.md`, allocation-run).
- Abgrenzungsrechnung: **beantwortet** — `ReconciliationRule`-Satz mit vier Regeltypen und Abstimmbrücke.
- Gebührenkalkulation (kommunal): **vertagt** mit dem Kommune-Paket.
