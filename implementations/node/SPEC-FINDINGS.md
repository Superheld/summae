# SPEC-FINDINGS (Node)

Dokumentierte Widersprüche zwischen Spec / Fixture / Modell (Root-`CLAUDE.md`:
„nicht raten, nicht die Fixture biegen, sondern dokumentieren und mit dem
nächstplausiblen Verhalten weiterbauen").

## NF-001 — Pack-Draft-Fixture `tenant-from-de-complete`: `defaults` im Manifest fehlte

**Befund (2026-06-21, Gate-1-Pack-Konformität).** Die Entwurfs-Fixture
`testsuite/fixtures/pack/de-composed-equals-de/tenant-from-de-complete-posts-identically.json`
erwartet `createTenant.result.taxationMethod = "cash"`, ihr Manifest `de-mini-regression`
trug aber **kein** `defaults`-Objekt — weder Manifest noch Module kodieren
`taxationMethod` irgendwo. Der Resolver leitet `defaults` laut Design
(`module-manifest-resolver.md` § 2/§ 4.1) ausschließlich aus dem Manifest ab; ohne
`defaults` greift der Engine-Default `accrual` ≠ erwartetes `cash`.

**Bewertung.** Authoring-Lücke in einer **explizit nicht-normativen Entwurfs-Fixture**
(„ENTWURF, nicht normativ; geht erst mit menschlicher Freigabe nach 70-testsuite").
Die Fixture spiegelt `core/create-tenant-profile`, dessen Profil
`defaults: {taxationMethod: cash, smallBusiness: false, vatPeriod: quarterly}` trägt —
das Manifest hatte diese Übernahme schlicht ausgelassen. Der Resolver ist korrekt.

**Resolution.** Da der korrekte Wert eindeutig ist (gespiegeltes `create-tenant-profile`
+ Design § 2) und es ein Pre-Freeze-Draft ist, wurde der Draft vervollständigt statt
verbogen: `defaults: {taxationMethod: "cash", smallBusiness: false, vatPeriod: "quarterly"}`
ergänzt — in beiden Manifest-Kopien (`tenant-from-…` **und** `resolve-de-complete-…`,
Pinning-Konsistenz `de-mini-regression@2026.1`), an Quelle (`70-testsuite`) und Spiegel.
Gilt auch für die PHP-Seite (gemeinsame Fixture).
