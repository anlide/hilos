<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;

/**
 * The agent class of a project's {@see AccountDataOwner} that moves its own rows of a merge loser
 * to the survivor.
 *
 * Only an owner with one agent per installation carries a merge part: a row that passes from the
 * loser's set into the survivor's writes into two sets, and only the owner of the whole table may
 * do that ({@see DbWriteGuard::guardCollectionWrite()}). A class registered per person is not
 * registered for the merge, even when it implements this interface.
 *
 * The owner records its result through the generic participant step, so it declares the
 * participant result claim (docs/agents/architecture/account-operations.md, "The participant
 * result claim"): {@see HilosDbContext::accountOperationResults} with `Add` in `OWNS_DB_SET`, its
 * full agent id as the set key, and {@see HilosDbContext::accountOperationAssignments} and
 * {@see HilosDbContext::accountOperations} in `READS_DB`. Without the claim the result row is
 * refused with `CreateNotAllowedException`.
 * SCAFFOLD: no live caller until the merge coordinator asks its owners (HIL-2000), live at the
 * merge switch (HIL-1999).
 */
interface AccountMergeProjectOwner
{
    /**
     * Moves the owner's rows of the loser to the survivor inside the transaction of the participant step.
     *
     * Only what the project's merge moves today moves here; every other row stays with the loser.
     *
     * @param AccountMergePart $part Operation, survivor and loser of the merge
     * @return array<string, int> Rows moved from the loser to the survivor per family the project names, empty when nothing moved
     * @throws HilosException When the owner cannot read or move its rows
     */
    public function mergeAccountPart(AccountMergePart $part): array;
}
