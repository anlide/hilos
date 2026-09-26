<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

use Hilos\Auth\AccountDeletion\DTO\AccountDeletionStateSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Builds one person's account deletion state (HIL-302).
 *
 * The one place the state is put together, so the profile page's first render and every
 * start and cancel fanned to the person's group say the same thing: the deletion that
 * stands, with the moment it was asked for and the moment it runs, or nothing.
 */
final class AccountDeletionStateProjector
{
    /** Page-data section slot carrying the state, on every profile page that draws the zone. */
    public const string SECTION = 'accountDeletion';

    /**
     * Builds the state.
     *
     * @param int $userId Person whose state it is
     * @return AccountDeletionStateSignalData The state
     * @throws HilosException When the lookup fails
     */
    public static function stateFor(int $userId): AccountDeletionStateSignalData
    {
        $deletion = Hilos::$db->accountDeletions->liveOf($userId);

        return new AccountDeletionStateSignalData($deletion === null ? null : [
            AccountDeletionStateSignalData::requestedAt => TimeHelper::sqlToMs($deletion->requestedAt),
            AccountDeletionStateSignalData::effectiveAt => TimeHelper::sqlToMs($deletion->effectiveAt),
        ]);
    }
}
