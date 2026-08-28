<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AuditTrail;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\FormatVersion;

/**
 * Where identifying data can sit in these books, and how much of it is actually there (F-CORE-041).
 *
 * The counterpart to systemDescription, which answers the same shape of question about auditing:
 * *what does this system record, and about what*. An operator preparing a record of processing
 * activities needs the same answer about people, and until now had to reconstruct it by reading the
 * schema by hand — which is a list that goes stale silently, because a field that has been renamed
 * still reads like a field.
 *
 * **Two halves, split along the project's own axis, and the split is the reason this belongs in
 * summae rather than in every application separately.**
 *
 * - **Where identifying data can sit is mechanism.** The partner aggregate has a name; the audit
 *   trail records an actor; a posting carries free text. That is true of the `us` pack exactly as of
 *   the `de` pack, it does not cite a statute, and it is what this projection reports.
 * - **Whether a given field *counts* as personal data is not mechanism.** Jurisdictions answer that
 *   differently, and a company identifier is personal data for a sole trader and not for a
 *   corporation. So this projection **never says "this is personal data"** — it says *this field
 *   holds free text an operator supplies*, and it counts what is present. The classification is the
 *   operator's, with their own legal advice; docs/gdpr-conformance.md §1 is summae's own reading of
 *   it for the German/EU case.
 *
 * **It reports shape and counts, never content.** `present` says how many partners carry an address,
 * not what any address says; `addressKeys` says which keys occur, not their values. A projection
 * built to help with a privacy obligation must not itself become the convenient place to read
 * everybody's data out of — and an operator asking "what do we hold" needs the inventory, not the
 * records, which journalExport already gives them.
 */
final readonly class PersonalDataDescriptionProjection
{
    public function __construct(
        private PartnerRepository $partners,
        private VoucherRepository $vouchers,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $partners = $this->partners->all();
        $vouchers = $this->vouchers->all();

        $addressKeys = [];
        $withAddress = 0;
        $withVatId = 0;
        foreach ($partners as $partner) {
            $data = $partner->jsonSerialize();
            $address = $data['address'] ?? null;
            if (is_array($address) && $address !== []) {
                ++$withAddress;
                foreach (array_keys($address) as $key) {
                    $addressKeys[(string) $key] = true;
                }
            }
            if (is_string($data['vatId'] ?? null)) {
                ++$withVatId;
            }
        }

        $actors = [];
        foreach ($this->audit->all() as $record) {
            $actors[$record->actor] = true;
        }

        $issuers = 0;
        foreach ($vouchers as $voucher) {
            if (is_string($voucher->jsonSerialize()['issuer'] ?? null)) {
                ++$issuers;
            }
        }

        $keys = array_keys($addressKeys);
        sort($keys);

        return [
            'formatVersion' => FormatVersion::CURRENT,
            // The declared shape: every place a value an operator typed can come to rest.
            // `freeText` marks the ones whose content summae neither constrains nor interprets —
            // the fields where anything at all can end up, which is what an inventory most needs
            // flagged.
            'fields' => [
                ['holder' => 'partner', 'field' => 'name', 'freeText' => true, 'required' => true, 'present' => count($partners)],
                ['holder' => 'partner', 'field' => 'vatId', 'freeText' => true, 'required' => false, 'present' => $withVatId],
                ['holder' => 'partner', 'field' => 'address', 'freeText' => true, 'required' => false, 'present' => $withAddress],
                ['holder' => 'voucher', 'field' => 'issuer', 'freeText' => true, 'required' => false, 'present' => $issuers],
                ['holder' => 'journalEntry', 'field' => 'text', 'freeText' => true, 'required' => false, 'present' => null],
                ['holder' => 'auditRecord', 'field' => 'actor', 'freeText' => true, 'required' => true, 'present' => count($actors)],
                [
                    // The one field that mirrors another: a diff of a partner change carries
                    // whatever the partner held. An inventory that lists partner.name and stops has
                    // missed the copy.
                    'holder' => 'auditRecord',
                    'field' => 'changes',
                    'freeText' => true,
                    'required' => true,
                    'present' => null,
                    'mirrors' => 'the fields of whatever record changed',
                ],
            ],
            // Which address keys this tenant's data actually uses. The format declares a recommended
            // shape and does not forbid others, so the only truthful answer to "what is in there" is
            // to look — which is exactly the question a hand-written inventory cannot answer.
            'addressKeys' => $keys,
            'counts' => [
                'partners' => count($partners),
                'vouchers' => count($vouchers),
                'distinctActors' => count($actors),
            ],
            // Stated rather than implied: an operator reading this must not conclude that summae has
            // classified anything for them.
            'classification' => 'none — summae reports where operator-supplied text can sit, not which of it is personal data under any jurisdiction',
        ];
    }
}
