<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Composition\TenantRecord;

/**
 * What a tenant *is*, apart from its books (SPEC-015).
 *
 * Everything else in this namespace persists a record the books are made of. This one persists the
 * tenant itself, and it exists because that configuration had no owner at all: the tax profile, the
 * dimension registry, the allocation scheme and the imported mappings were constructor arguments
 * rebuilt from whatever the caller passed on every open. Five operations changed them, wrote a
 * durable audit record about it, and lost the change with the process.
 *
 * The chart of accounts settles whether this is the library's business: it is seeded from the pack,
 * stored per tenant, changed by operations and read back by a projection, and nobody ever argued it
 * belonged to the embedding. A cost centre is master data the same way an account is.
 *
 * Scoped to one tenant like every other repository here, so `load` needs no argument. Listing the
 * tenants in a store is deliberately NOT part of this port: a repository here answers for the
 * tenant it was built for, and "which tenants exist" is a question about the store, which the
 * adapter answers.
 */
interface TenantRecordRepository
{
    public function load(): ?TenantRecord;

    public function save(TenantRecord $record): void;
}
