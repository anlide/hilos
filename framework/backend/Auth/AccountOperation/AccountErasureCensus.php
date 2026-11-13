<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\HilosException;

/**
 * What an erasure expects from whom: every activated participant asked about every account of the
 * circle, without writing anything.
 *
 * A participant is activated when any of its collections is mounted and its table is in the
 * schema; one whose tables this installation never created has nothing to erase and is not asked.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
final class AccountErasureCensus
{
    /**
     * @param AccountOperationCircle $circle Accounts of the erasure, root last
     * @param AccountErasureParticipantCatalog $catalog Participants of the installation
     * @return list<AccountOperationExpectation> Expectations by account in circle order, then by participant in catalog order
     * @throws DbCollectionNotReadableException When this process does not read a participant's collection
     * @throws NamedDatabaseException When a collection's database binding cannot be resolved
     * @throws HilosException When a participant cannot read its rows or name its owner
     */
    public static function of(AccountOperationCircle $circle, AccountErasureParticipantCatalog $catalog): array
    {
        $activated = array_values(array_filter(
            $catalog->participants(),
            static fn (AccountErasureParticipant $participant): bool => AccountOperationActivation::anyActivated($participant->collections()),
        ));
        $expectations = [];
        foreach ($circle->toArray() as $userId) {
            foreach ($activated as $participant) {
                array_push($expectations, ...$participant->expectationsFor($userId));
            }
        }

        return $expectations;
    }
}
