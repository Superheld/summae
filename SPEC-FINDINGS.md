# SPEC-FINDINGS

Befunde aus der Implementierung: Stellen, an denen Spec (v0.2), Fixtures und Modell
sich widersprechen oder etwas fehlt. Regel aus dem Briefing: **nicht raten, nicht
die Fixture ändern** — hier dokumentieren, mit dem nächstplausiblen Verhalten
weiterbauen. Fließt zurück ins Entscheidungslog der Wissensbasis.

Format je Befund:

```
## F-XXX: Kurztitel
- **Job:** JOB-NNN
- **Was:** Beschreibung des Widerspruchs / der Lücke
- **Wo:** Datei(en) + Abschnitt in Spec/Fixture/Modell
- **Gewähltes Verhalten:** Was die Implementierung jetzt tut
- **Vorschlag:** Empfehlung für Spec v0.3
```

---

## F-001: Kein Fehlercode für unbekannte voucherId

- **Job:** JOB-003
- **Was:** `E_ENTRY_NO_VOUCHER` ist definiert als „voucherId fehlt". Für eine
  *gesetzte, aber unbekannte* voucherId existiert kein Code; keine Fixture
  deckt den Fall ab.
- **Wo:** fehlerkatalog.md (E_ENTRY), api.md (post)
- **Gewähltes Verhalten:** Unbekannte voucherId wird ebenfalls als
  `E_ENTRY_NO_VOUCHER` gemeldet (Referenz-Prüfschritt 2, vor Konten).
- **Vorschlag:** Entweder explizit so festschreiben oder eigenen Code
  `E_VOUCHER_UNKNOWN` einführen + Fixture.

## F-002: E_ENTRY_NOT_FINALIZED in api.md, aber nicht im Fehlerkatalog

- **Job:** JOB-003
- **Was:** api.md listet `E_ENTRY_NOT_FINALIZED`* bei `reverse` (mit Fußnote
  „Entscheidung offene Frage 5"); der Fehlerkatalog (29 Codes, alle mit
  Fixture) kennt ihn nicht. Fixture finalize-reverse-period storniert eine
  *nicht* festgeschriebene Stornobuchung erfolgreich.
- **Wo:** api.md (Ledger-Tabelle) vs. fehlerkatalog.md vs. finalize-reverse-period.json (Step 9)
- **Gewähltes Verhalten:** `reverse` ist unabhängig vom Status zulässig
  (folgt Fixture + Katalog).
- **Vorschlag:** Fußnote in api.md auflösen — Zeile aus der Fehlerspalte
  streichen oder Verhalten für `entered` explizit definieren.

## F-003: Kein Fehlercode für „Jahresabschluss mit nicht festgeschriebenen Buchungen"

- **Job:** JOB-003
- **Was:** api.md verlangt für `closeFiscalYear` „alle Buchungen
  festgeschrieben", definiert aber keinen Code für den Verstoß; keine Fixture.
- **Wo:** api.md (Zeitraum-Semantik, closeFiscalYear)
- **Gewähltes Verhalten:** `E_PERIOD_OUT_OF_ORDER` (derselbe Code wie bei
  offenen Perioden — „Abschlussvoraussetzung verletzt").
- **Vorschlag:** Eigenen Code `E_FISCALYEAR_UNFINALIZED_ENTRIES` erwägen
  oder die Wiederverwendung dokumentieren.
