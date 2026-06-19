# Architektur (PHP)

PHP-spezifisch: Packages, Pfade, Adapter. Das **sprachneutrale Denkmodell**
(jurisdiktionsfreies Substrat → drei Politiksorten → Pack → Konfiguration) steht in
[`/docs/architektur.md`](../../../docs/architektur.md) — das gilt für alle
Implementierungen und ist beim Bauen Pflichtlektüre.

## Drei Packages, ein Repo

| Package | Composer-Name | Rolle |
|---|---|---|
| `packages/core` | `superheld/summae-core` | Framework-freier Fachkern. Die gesamte Buchführungslogik. Einzige Abhängigkeit: `brick/math`. |
| `packages/laravel` | `superheld/summae-laravel` | Adapter: DB-Persistenz (`illuminate/database`-Query-Builder, **kein ORM**), ServiceProvider, Migrationen. **Keine Fachlogik.** |
| `packages/cli` | `superheld/summae-cli` | Terminal-Werkzeug (`summae`), JSON-Ein/Ausgabe. Nutzt core + laravel-Persistenz. |

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
- **Database** (`packages/laravel/src/Repository/`, Klassen `Database*Repository`) —
  für die echte DB. Persistiert die Aggregat-Innereien als JSON-Dokumente in
  `summae_*`-Tabellen, exakt in Published-Language-Form. Nutzt den
  **`illuminate/database`-Query-Builder** (`$connection->table(...)`), **kein ORM**
  (kein `extends Model`). Rollenbasiert benannt (nicht nach dem Tool) — das
  Node-Pendant `@superheld/summae-knex` heißt seine Klassen ebenso `Database*` und
  nutzt Knex als Query-Builder. Siehe `/docs/architektur.md`.

Zusammengebaut wird ein Mandant durch:

- `Tenant::inMemory(...)` — der Kern für In-Memory-Betrieb.
- `DatabaseTenantFactory::build(...)` — derselbe `Tenant`, nur mit DB-Ports.

Beide liefern denselben `Tenant`; alles darüber ist identisch.

## Fachliche Schichtung

Das Schichtenmodell (Substrat → Politiksorten → Pack → Konfiguration) ist
sprachneutral und steht in [`/docs/architektur.md`](../../../docs/architektur.md).
In PHP konkret: Der `core` ist das Substrat; Regelmodul-/Pack-Daten werden der
Factory (`Tenant::inMemory` / `DatabaseTenantFactory::build`) als Daten übergeben;
die App ist das Laravel-Projekt des Nutzers.

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
      → JournalRepository::append   (Port → In-Memory oder Database)
      → OpenItem-Automatik bei AR/AP
      → AuditTrail::append
  → PostResult (entry + erzeugte offene Posten)
```

Lesen läuft nie über gespeicherte Salden, sondern über die Projektionen in
`packages/core/src/Projection/`.
