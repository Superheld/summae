# GDPR / DSGVO — what personal data summae holds, and who answers for it

**Status: 2026-08-27.** Reference: Regulation (EU) 2016/679 (GDPR / DSGVO), with the German
retention duties it collides with (§ 147 AO, § 257 HGB).

This document exists because the [GoBD census](gobd-conformance.md) had no counterpart. summae
stores names, addresses and tax identifiers, writes down which person performed which operation,
and exports all of it for an audit — and until this file, the repository did not contain the word
*personal data*. Silence is the wrong answer for a library whose whole pitch is that it writes down
what it does and what it does not.

**This is not legal advice and not a certification.** It states which GDPR-relevant facts are
mechanically checked here and which are not. Nothing more.

| Status | Meaning |
|---|---|
| ✅ **verified** | A named test or fixture fails if this stops being true. Run it. |
| ⚠️ **open** | summae should do this and does not yet. Named, scoped, not hidden. |
| ➖ **not answerable here** | Cannot be provided by a library at all — it belongs to the controller or the embedding application. Listed so it is not mistaken for covered. |

> **The one sentence to carry away:** you are the **controller**, summae is not a processor.
> It is a library that runs inside your process, opens no connection, and sends nothing anywhere.
> Every ➖ row below is yours, and a green test suite says nothing about whether you have handled it.

---

## 1. What personal data summae can hold

This is the inventory an Art. 30 record needs, and it is the part of this document most likely to
be read in a hurry. Every field named here exists in
[`testing/testsuite/schema/format.schema.json`](../testing/testsuite/schema/format.schema.json), and
the guard test over this document fails if one of them stops existing.

| Where | Field | What it typically contains | Note |
|---|---|---|---|
| `partner` | `name` | company or natural-person name | **required** — a partner cannot be created without one (`partner-master-data`) |
| `partner` | `vatId` | VAT identification number | for a sole trader this is quasi-identifying |
| `partner` | `address` | postal address | **an unconstrained object** — see §5, this is the one row that is a finding rather than a fact |
| `partner` | `kind`, `status`, `accountNumbers`, `paymentTermsDays` | commercial attributes | personal only by association with `name` |
| `auditRecord` | `actor` | **who performed the operation** | free string supplied by the embedding — normally an employee. Written on every state change (`audit-trail`) |
| `auditRecord` | `changes` | before/after of the changed fields | mirrors whatever the changed record held, including a partner's name and address — which is why `erasePartner` takes the records about a partner with it rather than only the partner row |
| `voucher` | `issuer` | who issued the document | free text |
| `journalEntry` | `text` | posting text | free text, and in practice where names end up (*"Rechnung Meier, Beratung Mai"*) |
| `voucher` | `documents` | references to attached documents | summae stores references, **never the document bytes** |

**Two things this list is not.** It is not a claim that summae *needs* all of it — `name` is the
only required field in the list. And it is not exhaustive about *content*: three of these fields are
free text, so what actually lands in them is decided by the application, not by summae.

---

## 2. Data-subject rights (Art. 15–22)

| Right | Status | Where it stands |
|---|---|---|
| **Art. 15 — access** | ⚠️ **the parts exist, the answer does not** | Everything needed is readable: `partner` for the master data, `openItems`/`accountSheet` for what is owed, `journal` for the postings, `auditLog` for who touched the record (`audit-log-filtered`). What does not exist is *one* operation that assembles them for one data subject — nor even a `partners` projection: the partner list is writable through four operations and readable only as a stream inside `journalExport`. Today the application joins four projections itself, and each one that forgets a source produces an incomplete disclosure. Named in §7. |
| **Art. 16 — rectification** | ✅ | `updatePartner` changes master data and writes the before/after into the audit trail, so the correction is itself documented (`partner-master-data`, `audit-trail-creations`). The books are *not* rectified this way, and must not be: a wrong posting is corrected by reversal, never by edit. |
| **Art. 17 — erasure** | ✅ **for what the duty does not cover**, ➖ for the rest | Two halves, and they need separating. The books are **not** erasable: Art. 17(3)(b) exempts processing required by a legal retention duty, § 147 AO / § 257 HGB impose one, and the append-only journal is that duty's technical form rather than a problem to solve. What the duty does **not** cover is a partner no voucher and no open item has ever named — a record created by a typo — and that one is erasable since 0.15.1: `erasePartner` removes it, erases the trail's records about it (`createPartner` writes the name into `changes`, so leaving them would erase nothing), reports how many went, and leaves a single record naming only id, actor and moment. **Since format 0.8 the trail's record is emptied rather than deleted, and the reason is not a compromise.** Its rows are hash-chained (`F-CORE-043`), so deleting one would break the chain at its successor for good and every later verification would report a manipulation that never happened — a lawful erasure and an attack would look alike, which serves neither the data subject nor the auditor. What is left is a **shell**: both hashes, and nothing else. `actor`, `objectType`, `action` and `changes` are gone, `objectId` points at the record itself, and the reserved `objectType: "redacted"` keeps it out of every filter about a real object, so `auditLog` about the erased partner returns the erasure and nothing more. The shell's own content can no longer be verified against its hash — which is precisely what an erasure means, and `auditTrailIntegrity` counts shells separately so that is never read as a silent pass. It refuses with `E_PARTNER_IN_USE` and the reference counts when the books do carry the partner. Fixture `partner-erasure`, plus `PartnerErasureTest` / `partner-erasure.test.ts`. |
| **Art. 18 — restriction** | ⚠️ partly | `partner.status = inactive` exists and is pinned (`partner-status`), but its own source says it is *"a state, not a control"*: an inactive partner still settles, still reads, still posts. That is right for the commercial meaning of inactive and wrong as a restriction of processing. summae has no mechanism that actually withholds a record from further use. |
| **Art. 20 — portability** | ✅ | `journalExport` emits the full data set, including the partner stream, in a documented machine-readable format with a field catalogue (`journal-export-z3-current`). `auditDataExport` does the same on the US side. Whether that format is what the recipient wants is not summae's call. |
| **Art. 21/22 — objection, automated decisions** | ➖ | summae makes no decisions about people. It has no profiling, no scoring, no automated individual decision-making of any kind. |

---

## 3. Roles: who is controller, who is processor (Art. 24, 28)

| Question | Status | Answer |
|---|---|---|
| Is summae a processor under Art. 28? | ➖ **no, and no DPA is needed with us** | summae is a library linked into your application. It opens no socket, calls no service, and ships no telemetry — the framework-free rule in the core is enforced by lint in Node and by PHPStan boundaries in PHP, for architectural reasons, but the privacy consequence is real: there is no path out. Nobody at summae ever holds your data. |
| Who is the controller? | ➖ | You, the embedding operator. summae never decides purposes or means. |
| Sub-processors | ➖ | None exist. The database is yours; the adapters write into your schema. |

---

## 4. Principles (Art. 5) and privacy by design (Art. 25)

| Principle | Status | Where it stands |
|---|---|---|
| **Lawfulness, purpose limitation** (Art. 5(1)(a)(b)) | ➖ | A library cannot know your legal basis. Bookkeeping is normally Art. 6(1)(c) — a legal obligation — which is also why the retention conflict in §2 resolves the way it does. |
| **Data minimisation** (Art. 5(1)(c)) | ⚠️ | Two sides. In summae's favour: only `name` is required, everything else is optional, and the pack layer is data about *jurisdictions*, never about people — no shipped pack contains a personal datum. Against it: the address is a declared *recommended* shape rather than a closed one (see §7 row 2 for why closing it would invalidate lawful data), so the format still accepts an email address or a date of birth under a key of its own. What changed is that it no longer does so **unnoticed**: `personalDataDescription.addressKeys` reports every key a tenant actually holds, which turns an invisible funnel into a readable one. Detecting is not preventing, and the row stays ⚠️ for that reason. |
| **Accuracy** (Art. 5(1)(d)) | ✅ | `updatePartner` plus the audit trail: a correction is possible and is itself recorded. |
| **Storage limitation** (Art. 5(1)(e)) | ➖ **and in tension with retention** | Nothing in summae expires. After the retention period ends, the duty to keep stops and the duty to erase resumes — and summae offers no mechanism for that transition, because it has no delete at all (§7). Today this is entirely the operator's, at the database level. |
| **Integrity** (Art. 5(1)(f), Art. 32) | ➖ | Access control, encryption at rest, backups and separation of duties are properties of your deployment. summae cannot defend its own storage: a direct `UPDATE` against a `summae_*` table bypasses append-only, finalization and the audit trail alike — the same sentence the GoBD document ends on, and it is a privacy statement as much as a compliance one. |
| **By design and by default** (Art. 25) | ⚠️ partly | The substrate/pack split keeps personal data out of everything shipped, and `documents` holds references rather than bytes, so summae never becomes the store for scanned invoices full of third-party data. What is missing is the deliberate part: no default limits what goes into the free-text fields, and `journalEntry.text` in particular is where names end up without anyone deciding that they should. `personalDataDescription` makes the surface visible; nothing narrows it. |

---

## 5. Records of processing (Art. 30)

✅ since 0.15.1, with one honest limit.

Art. 30 asks a controller to describe categories of data subjects, categories of personal data,
recipients and erasure periods. summae answers the structurally identical question about *auditing*
through `systemDescription` (`system-description-claims`, F-IO-007), and now answers it about people
through **`personalDataDescription`** (`personal-data-description`, F-CORE-041): every field where
operator-supplied free text can come to rest, how many records actually carry one, which address keys
this tenant really uses, and how many distinct actors the trail knows.

**What it will not do is classify.** It reports that a field holds free text summae neither
constrains nor interprets; it never reports that a field *is* personal data, because that answer is
jurisdictional — a company identifier is personal data for a sole trader and not for a corporation.
The payload says so in a `classification` field rather than leaving a reader to assume. §1 of this
document is summae's own reading for the German/EU case, hand-written on purpose and machine-checked
against the schema; the projection is the generated half an operator's own record can be built from.

---

## 6. The retention collision, stated once and properly

The question every embedding asks eventually: *a customer demands erasure, and the books are
append-only — who wins?*

The retention duty wins, and this is settled law rather than a summae opinion. Art. 17(3)(b) GDPR
disapplies the right to erasure where processing is necessary for compliance with a legal
obligation; § 147 AO and § 257 HGB impose exactly such an obligation on bookkeeping records for six
to ten years. The correct answer to the data subject is a **refusal with a reason and a date**, not
a deletion — and Art. 17(3)(b) is the reason, the end of the retention period the date.

Three consequences worth being explicit about:

1. **summae's append-only journal is not a GDPR problem.** It is the technical form of a legal
   obligation. A bookkeeping system that could erase a posting would be the compliance defect.
2. **The exemption covers the books, not everything adjacent.** A partner record that never entered
   the books is not covered by any retention duty, and there the right to erasure applies
   undiminished. `erasePartner` executes it, and refuses the moment a voucher or an open item names
   the partner — the boundary between the two halves, drawn in one operation.
3. **The exemption ends.** When retention lapses, erasure becomes due again. Nothing in summae
   tracks that date or acts on it; today the operator does it at the database level, accepting that
   this bypasses the library — which is the one case where going around summae is *correct*.

---

## 7. The open list, in one place

| # | Item | Kind | Why it is not done |
|---|---|---|---|
| 1 | ~~No erasure for a partner the books never referenced~~ | ✅ **closed 2026-08-28** | Built as `erasePartner` (F-CORE-040). **The scoping in this row was wrong in a way worth recording:** it proposed refusing when *any* record referenced the id, *audit records included* — and `createPartner` always writes one, so the operation could never have succeeded. The guard is bookkeeping references only (voucher, open item); the audit records about the partner are erased **with** it, because they carry the name and address in `changes` and leaving them standing moves the personal data rather than removing it. A single record replaces them, carrying `existed: true → false` and no personal payload — a shape the audit-trail contract test forced, since it requires every record to have a before/after diff, and an empty one would have been an exception where a truthful diff costs nothing. |
| 2 | ~~`partner.address` accepts anything~~ | ✅ **closed 2026-08-28**, and not the way this row proposed | The recommended shape is declared — `line1`, `line2`, `postalCode`, `city`, `region`, `country` (ISO 3166-1 alpha-2). **`additionalProperties` stays open**, which is the correction: closing it would have made an export of lawful data invalid, because books written before the shape existed carry whatever they carry, and a schema that rejects them turns a privacy improvement into a data-loss event. So the declaration says what to *write*, and `personalDataDescription.addressKeys` says what is actually *there* — which is the half a declaration alone can never supply, and the half an inventory actually needs. No street/houseNumber split: it does not survive contact with addresses outside the German-speaking world. |
| 3 | ~~No Art. 30 building block~~ | ✅ **closed 2026-08-28** | `personalDataDescription` (F-CORE-041), the counterpart to `systemDescription`: `fields[]` with holder, field, `freeText`, `required` and `present`; `addressKeys[]`; `counts`. The axis held — *where* identifying fields sit is mechanism and is what the projection reports, while *whether* a field counts as personal data is jurisdictional and is what it deliberately refuses to say, stating that refusal in a `classification` field so no reader mistakes an inventory for advice. It reports shape and never content, which is the constraint that kept it from becoming a convenient way to read everybody's data out. §1 of this document stays hand-written and stays schema-checked; the projection is the machine-readable answer for an operator's own record. |
| 4 | **No single Art. 15 answer** (scoped in [`proposals/gdpr-open-rights.md`](proposals/gdpr-open-rights.md)) | ⚠️ open | The sources all exist; the assembly does not (§2). Lower value than #1 and #3: an application that already reads four projections can join them, and the failure mode is an incomplete disclosure rather than an unlawful retention. |
| 5 | Restriction of processing (Art. 18) has no mechanism | ➖ **deliberate for now** | `partner.status` is a commercial state on purpose and should not be overloaded into an access control — the same distinction the code already draws between `Partner.deactivate` (a state) and `Account.lock` (a control). A real restriction would need a gate in front of every read, which is a constraint-socket question, and that socket has one predicate today. |
| 6 | Retention-period expiry | ➖ | summae holds no retention clock and will not: the periods are jurisdictional (§ 147 AO is six or ten years depending on the class of record) and the decision to erase is the controller's. The library's contribution is the entry date, which it already publishes. |

---

## 8. What this document is not

Not a legal opinion, not a certification, not a Verzeichnis von Verarbeitungstätigkeiten. It is an
inventory plus an honest status per obligation. The ➖ rows are the operator's, and there are more of
them here than in the GoBD document — which is the correct shape for a library that never sees your
data, and is exactly why writing them down matters.
