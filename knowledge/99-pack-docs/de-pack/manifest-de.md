# Manifest — `de` (Deutschland)

```
pack: de · version: 2026.16 · formatVersion: 0.9
Datei: pack-library/de-pack/de.json · 14 Module · frühere Fassungen in versions/
```

## Zweck

Das kuratierte Bündel aller DE-Module plus `packPolicy`-Kopie und `defaults`. Ein Mandant pinnt es
per `id` (+ optional `version`); `createTenant({"pack": "de"})` löst es **einmal** zum
`ResolvedPack` auf und arbeitet danach nur noch damit. Ein Upgrade ist explizit, nie still.

> **Der Pack heißt `de`, nicht `de-complete`.** Dieses Dokument beschrieb bis 2026-08-27 ein
> Manifest `de-complete@2026.1` mit acht Modulen unter alten IDs — ein Produkt, das es nicht gibt;
> wer daraus abschrieb, bekam `E_PROFILE_UNKNOWN` (IMPL-034). Seither prüft `PackDocsTest` /
> `pack-docs.test.ts` auch die Manifest-Dokumente gegen das echte Manifest.

## Modulliste

Alle Module sind DE-eigen — kein `dependsOn` über Pack-Grenzen. Reihenfolge im Manifest ist ohne
Bedeutung; der Resolver sortiert topologisch nach `dependsOn`. Drei `constraint`-Module sind kein
Widerspruch: sie **addieren** sich, anders als `policy`, von dem es genau eines geben darf.

| `kind` | `id` | Version |
|---|---|---|
| `accounts` | `de-konten` | 2026.8 |
| `tax` | `de-ust` | 2026.4 |
| `mapping` | `de-bilanz` | 2026.5 |
| `mapping` | `de-guv` | 2026.3 |
| `mapping` | `de-euer` | 2026.7 |
| `depreciation` | `de-afa` | 2026.7 |
| `assetAccounts` | `de-assets` | 2026.2 |
| `policy` | `de-policy` | 2026.1 |
| `resultAppropriation` | `de-ergebnisverwendung` | 2026.1 |
| `productionCost` | `de-herstellungskosten` | 2026.1 |
| `inventory` | `de-vorraete` | 2026.1 |
| `provisions` | `de-rueckstellungen` | 2026.1 |
| `deferrals` | `de-rechnungsabgrenzung` | 2026.1 |
| `inputTaxAdjustment` | `de-vorsteuerberichtigung` | 2026.1 |
| `legalForms` | `de-rechtsformen` | 2026.1 |
| `constraint` | `de-entgeltminderung` | 2026.1 |
| `constraint` | `de-kleinunternehmer` | 2026.1 |
| `constraint` | `de-kapitalgesellschaft` | 2026.1 |

Was jedes Modul enthält, steht in seinem eigenen Dokument (`modul-1` … `modul-13`, Übersicht im
[README](README.md)).

## Steuerschlüssel

`USt19` · `USt7` · `VSt19` · `VSt7` · `RC13b` · `igL` · `USt19WA` · `IGE19` · `IGE7` · `AUSFUHR`

Die Liste im Manifest ist die **Auswahl**: das Modul `de-ust` darf mehr Schlüssel mitbringen, im
Mandanten landen genau diese. Ein hier genannter Schlüssel, den kein `tax`-Modul liefert, ist
`E_PACK_UNRESOLVED_REF` (Resolver-Invariante I4).

## `defaults` und `packPolicy`

```jsonc
"defaults": {"taxationMethod": "cash", "smallBusiness": false, "vatPeriod": "quarterly"},
"packPolicy": {"roundingMode": "halfUpAwayFromZero", "taxRoundingGranularity": "perVoucher", "currencyScale": 2}
```

`defaults` sind Mandanten-Voreinstellungen (Steuerprofil beim Anlegen), `packPolicy` sind
Rechenparameter, die den jurisdiktionsfreien Kern parametrisieren — Rundungsart, Rundungsgranularität
der Steuer und die Nachkommastellen der Währung. **Kein `vatPeriods`:** DE meldet monatlich,
quartalsweise oder jährlich, und das ist genau die Substrat-Voreinstellung (SPEC-016) — ein Pack
deklariert die Liste nur, wenn sie davon abweicht.

## Versionierung

Eine veröffentlichte `(id, version)` ist eingefroren. Jede Änderung an einem Modul hebt dessen
Version, und weil ein Bündel *ist*, was es referenziert, hebt eine geänderte Modulreferenz auch die
Version des Manifests. Die alte Fassung bleibt daneben liegen (`versions/de-<version>.json`), damit
ein gepinnter Mandant weiter auflöst; ohne Version gefragt, gewinnt die **höchste** nach
Code-Point-Ordnung. `PackVersionIdentityTest` weist zwei Dateien mit derselben veröffentlichten
Identität ab.
