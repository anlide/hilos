<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * What a merge expects from the project's owners: every activated owner of the project's
 * {@see AccountDataOwnerDirectory} asked about the loser, without writing anything.
 *
 * An owner is activated when any of its collections is mounted and its table is in the schema; one
 * whose tables this installation never created has nothing to move and is not asked. The survivor
 * is never asked: its rows stay where they are.
 * SCAFFOLD: no live caller until the merge coordinator opens its operation (HIL-2000), live at the
 * merge switch (HIL-1999).
 */
final class AccountMergeProjectCensus
{
    /**
     * @param int $loserUserId Loser of the merge
     * @return list<AccountOperationExpectation> Expectations about the loser, by owner in directory order
     * @throws InvalidTopologyException When the project's directory is malformed
     * @throws DbCollectionNotReadableException When this process does not read an owner's collection
     * @throws NamedDatabaseException When a collection's database binding cannot be resolved
     * @throws HilosException When an owner cannot read its rows or name its address
     */
    public static function of(int $loserUserId): array
    {
        $expectations = [];
        foreach (Hilos::accountDataOwnerDirectoryClass()::declaredOwners() as $owner) {
            $participant = new AccountMergeProjectParticipant($owner);
            if (AccountOperationActivation::anyActivated($participant->collections())) {
                array_push($expectations, ...$participant->expectationsFor($loserUserId));
            }
        }

        return $expectations;
    }
}
