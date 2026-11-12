<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Users\AccountErasure;

/**
 * The agent class of a project's {@see AccountDataOwner} that erases its own rows of one account.
 *
 * The class of the agent registered under the owner's type implements it; an owner whose class
 * does not is treated as unregistered, and the coordinator waits while the account has rows.
 *
 * The owner records its result through the generic participant step, so it declares the
 * participant result claim (docs/agents/architecture/account-operations.md, "The participant
 * result claim"): {@see HilosDbContext::accountOperationResults} with `Add` in `OWNS_DB_SET`, its
 * full agent id as the set key, and {@see HilosDbContext::accountOperationAssignments} and
 * {@see HilosDbContext::accountOperations} in `READS_DB`. Without the claim the result row is
 * refused with `CreateNotAllowedException`.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
interface AccountErasureProjectOwner
{
    /**
     * Deletes the owner's rows of one account inside the transaction of the participant step.
     *
     * Files the rows pointed at are named, not removed: a file gone from disk does not come back
     * if the transaction rolls back.
     *
     * @param AccountErasurePart $part Operation and account to erase
     * @return AccountErasure Rows deleted per family and the registry files they pointed at
     * @throws HilosException When the owner cannot read or delete its rows
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure;
}
