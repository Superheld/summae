# Architektur

## Drei Packages, ein Repo

| Package | Composer-Name | Rolle |
|---|---|---|
| `packages/core` | `superheld/summae-core` | Framework-freier Fachkern. Die gesamte Buchführungslogik. Einzige Abhängigkeit: `brick/math`. |
| `packages/laravel` | `superheld/summae-laravel` | Adapter: Eloquent-Persistenz, ServiceProvider, Migrationen. **Keine Fachlogik.** |
| `packages/cli` | `superheld/summae-cli` | Terminal-Werkzeug (`rw`), JSON-Ein/Ausgabe. Nutzt core + laravel-Persistenz. |

Daneben `superheld/summae-php` (`implementations/php/composer.json`) — die
PHP-Entwickler-Werkbank, **kein** ausgeliefertes Paket. `runner/` ist der
Fixture-Runner (nicht veröffentlicht, nur Konformitätsprüfung).

## Warum der Kern framework-frei ist

Lackmustest: *„Würde diese Zeile auch in einem Symfony- oder Node-Projekt Sinn
ergeben?"* → gehört in den Core. Kein `use Illuminate\…` in `packages/core`.

Drei Gründe:

1. **Die Konformitätssuite läuft gegen den Core in Millisekunden** (In-Memory-
   Port, ohne Laravel-Boot, ohne DB). Ein roter Test ist dann eindeutig ein
   Fachfehler, kein Persistenzfehler.
2. **Laravel bewegt sich, Buchführung nicht.** Major-Upgrades fassen nur den
   dünnen Adapter an; der geprüfte, GoBD-relevante Kern bleibt unberührt.
3. **Mehrsprachigkeit.** Derselbe Schnitt spiegelt sich später in Node
   (`core` + `nestjs/express`-Adapter) und Python.

## Hexagonal: Ports & Adapter

Der Kern definiert **Ports** (Interfaces in `packages/core/src/Port/`) und kennt
keine konkrete Persistenz:

```
AccountRepository   FiscalYearRepository   VoucherRepository
JournalRepository   OpenItemRepository     PartnerRepository
AssetRepository     AuditTrail
```

Zwei Adapter-Sätze implementieren sie:

- **In-Memory** (`packages/core/src/InMemory/`) — für Tests, Konformitätsläufe,
  die CLI-Logik. Schnell, ohne I/O.
- **Eloquent** (`packages/laravel/src/Repository/`) — für die echte DB.
  Persistiert die Aggregat-Innereien als JSON-Dokumente in `rw_*`-Tabellen,
  exakt in Published-Language-Form.

Zusammengebaut wird ein Mandant durch:

- `Tenant::inMemory(...)` — der Kern für In-Memory-Betrieb.
- `EloquentTenantFactory::build(...)` — derselbe `Tenant`, nur mit DB-Ports.

Beide liefern denselben `Tenant`; alles darüber ist identisch.

## Drei fachliche Schichten

1. **Kern-Engine** (`Ledger`, Projektionen) — kennt kein Gesetz.
2. **Regelmodule** — Steuersätze, Kontenrahmen, Mappings, GWG-Grenzen als
   versionierte **Daten** (App-Schicht, der Factory übergeben). Lackmustest:
   Zitiert Code einen Paragraphen → falsche Schicht, das gehört in Daten.
3. **App** — nicht unser Problem (das Laravel-/Node-Projekt des Nutzers).

## Eiserne Invarianten

- **Journal append-only; Salden sind Projektionen.** Nie einen Saldo speichern —
  jede SuSa/Bilanz/EÜR wird aus dem Journal neu berechnet.
- **Geld nie als Float.** `Money` auf `brick/math`, half-up (kaufmännisch),
  `allocate` mit Largest-Remainder. Siehe [konformitaet.md](konformitaet.md).
- **Determinismus.** Gleiche Eingabe → byte-identisches Ergebnis (inkl.
  Sortierung, Rundung), über alle Implementierungen.

## Genereller Einstiegspunkt: `TenantOperations`

`packages/core/src/Composition/TenantOperations.php` ist der Dispatcher für
**alle** Operationen (`post`, `postVoucher`, `settle`, …) und Projektionen
(`trialBalance`, `vatReturn`, `journalExport`, …) eines Mandanten — Namen exakt
nach API-Spec. CLI und Konformitäts-Runner nutzen denselben Dispatcher; das
hält die Operationsliste an *einer* Stelle.

## Datenfluss einer Buchung (Beispiel)

```
postVoucher(input)
  → TaxService::expand     (Steuerexpansion, Rundung pro Beleg)
  → Ledger::post           (Prüfreihenfolge, Invarianten, Journalnummer)
      → JournalRepository::append   (Port → In-Memory oder Eloquent)
      → OpenItem-Automatik bei AR/AP
      → AuditTrail::append
  → PostResult (entry + erzeugte offene Posten)
```

Lesen läuft nie über gespeicherte Salden, sondern über die Projektionen in
`packages/core/src/Projection/`.
