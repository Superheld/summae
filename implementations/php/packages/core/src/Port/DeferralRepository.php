<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Policies\Expansion\Deferrals\Deferral;

/**
 * Prepaid and deferred items (F-CORE-053).
 *
 * Shaped like `AssetRepository` and `ProvisionRepository`: a record with a plan and a history, read
 * back by a run that has to know what it already booked. The whole point of the port is that the
 * release schedule survives the process — a plan nobody can read back is a plan somebody keeps in
 * their head, which is what this operation exists to end.
 */
interface DeferralRepository
{
    /**
     * Deliberately no `byId`. The release run reads them all and the register reports them all;
     * nothing in the core asks for a single deferral. An interface method nobody calls is a burden
     * on every adapter author for a convenience the core does not have.
     */
    public function add(Deferral $deferral): void;

    public function save(Deferral $deferral): void;

    /** @return list<Deferral> in the order they were recognised */
    public function all(): array;
}
