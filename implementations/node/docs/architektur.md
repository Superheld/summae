# Architektur (Node/TypeScript)

Node-spezifisch: Pakete, Pfade, Adapter. Das **sprachneutrale Denkmodell**
(jurisdiktionsfreies Substrat → drei Politiksorten → Pack → Konfiguration) steht in
[`/docs/architektur.md`](../../../docs/architektur.md) und im Root-`CLAUDE.md` — das gilt
für alle Implementierungen und ist beim Bauen Pflichtlektüre. PHP ist die Referenz; Node
spiegelt sie **1:1** (Byte-Parität ist Vertrag, siehe [`konformitaet.md`](konformitaet.md)).

## Pakete, ein pnpm-Workspace

| Paket | npm-Name | Rolle |
|---|---|---|
| `packages/core` | `@superheld/summae-core` | Framework-freier Fachkern. Gesamte Buchführungslogik. Einzige Laufzeit-Abhängigkeit fürs Rechnen: `big.js`. |
| `packages/knex` | `@superheld/summae-knex` | Adapter: DB-Persistenz über **Knex** als Query-Builder (better-sqlite3 / pg), **kein ORM**. Klassen rollenbasiert `Database*`. **Keine Fachlogik.** |
| `packages/cli` | `@superheld/summae-cli` | Terminal-Werkzeug (`summae init|op|report`), JSON-Ein/Ausgabe, persistente SQLite. Nutzt core + knex. |

Daneben `runner/` — der Fixture-Runner (nicht veröffentlicht, nur Konformitätsprüfung).

## Warum der Kern framework-frei ist

Lackmustest: *„Würde diese Zeile auch in einem PHP- oder Python-Projekt Sinn ergeben?"* → gehört
in den Core. **Technisch erzwungen:** eslint `no-restricted-imports` verbietet in
`packages/core/**` Web-Frameworks, DB-Treiber und ORMs (`express`/`knex`/`pg`/`prisma`/`typeorm`/…)
— ein Framework-Import im Kern ist ein Lint-Fehler. Pendant zu „kein `use Illuminate\…`" der PHP-Seite.

## Hexagonal: Ports & Adapter

Der Kern definiert **Ports** (Interfaces in `packages/core/src/port.ts`) und kennt keine konkrete
Persistenz:

```
AccountRepository   FiscalYearRepository   VoucherRepository
JournalRepository   OpenItemRepository     PartnerRepository
AssetRepository     AuditTrail
```

Zwei Adapter-Sätze implementieren sie:

- **In-Memory** (`packages/core/src/in-memory.ts`) — für Tests, Konformitätsläufe, CLI-Logik. Ohne I/O.
- **Database** (`packages/knex/src/repositories.ts`, Klassen `Database*Repository`) — echte DB.
  Persistiert die Aggregat-Innereien als JSON in `summae_*`-Tabellen, bit-genau im Schema der
  PHP-Seite (geteilte DB). Nutzt **Knex** (`$db.table(...)`), **kein ORM**.

Zusammengebaut wird ein Mandant durch:

- `Tenant.inMemory(...)` (`packages/core/src/composition/tenant.ts`) — Kern für In-Memory-Betrieb.
- `DatabaseTenantFactory.build(...)` (`packages/knex/src/database-tenant-factory.ts`) — derselbe
  `Tenant`, nur mit DB-Ports.

Beide liefern denselben `Tenant`; alles darüber ist identisch. (Beide rufen intern
`Tenant.fromPorts(...)` — *der* eigentliche Assembler, der die Services über die gereichten Ports verdrahtet.)

## Genereller Einstiegspunkt: `TenantOperations`

`packages/core/src/composition/tenant-operations.ts` ist der Dispatcher für **alle** Operationen
(`post`, `postVoucher`, `settle`, …) und Projektionen (`trialBalance`, `vatReturn`, `journalExport`, …)
— Namen exakt nach API-Spec. CLI und Konformitäts-Runner nutzen denselben Dispatcher; das hält die
Operationsliste an *einer* Stelle. (Rezept „neue Operation anbauen": [`entwicklung.md`](entwicklung.md).)

## Konfiguration: Regelmodule & Pack

Die Engine isst *ein* aufgelöstes `ruleModules`-Bündel (Kontenrahmen, taxCodes, Mappings,
assetAccounts, depreciation, packPolicy). Dahin führen zwei Wege:

- **inline** — das Bündel wird direkt gereicht (CLI heute via `summae.json`; Fixtures via `setup`).
- **komponiert** — ein Manifest + Module aus der ausgelieferten `pack-library/` werden vom
  `PackResolver` (`packages/core/src/composition/pack-resolver.ts`) aufgelöst; der Loader
  `runner/src/pack-library.ts` liest die Bibliothek von der Platte. `createTenant(pack:"…")`
  pinnt das Manifest. **`packPolicy` parametrisiert** den Kern (`currencyScale`→`Currency`,
  `taxRoundingGranularity`→`TaxService`). Details: Root-`CLAUDE.md`, Sektion „Packs konkret".

**CLI wählt ein Pack.** `summae init --pack de` lädt das Pack aus der ausgelieferten
`pack-library/` und schreibt die aufgelösten Regeln in den Arbeitsbereich:
`packages/cli/src/pack-library.ts` (`loadPackLibrary` inhaltsbasiert + `packToRules` =
`resolvePack`→`ruleModulesFromResolved`→CLI-`rules`-Struktur). `--pack-library <dir>` übersteuert den
Pfad (Default: Repo-Wurzel/`pack-library`). Alternative zu `--pack`: eine eigene `--rules`-Datei.

## Datenfluss einer Buchung (Beispiel)

```
postVoucher(input)
  → TaxService.expand     (Steuerexpansion, Rundung je packPolicy)
  → Ledger.post           (Prüfreihenfolge, Invarianten, Journalnummer)
      → JournalRepository.append   (Port → In-Memory oder Database)
      → OpenItem-Automatik bei AR/AP
      → AuditTrail.append
  → PostResult (entry + erzeugte offene Posten)
```

Lesen läuft nie über gespeicherte Salden, sondern über die Projektionen in
`packages/core/src/projection/`.
