<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;

/**
 * Refuses a person who is gone or folded into someone else.
 *
 * The one copy of that check on the way to the person's agent. A coordinator calls it before the
 * hop, so a late frame never raises the agent. The agent calls it again on the race where the hop
 * left before the erasure or the merge arrived. People and their merges are read by every process,
 * so the check needs no read claim of its own.
 */
final class AddressablePerson
{
    /**
     * @param int $userId Person a frame would be addressed to
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When a row cannot be loaded
     */
    public static function require(int $userId): void
    {
        if (Hilos::$db->users[$userId] === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }
        if (Hilos::$db->userMerges[$userId] !== null) {
            throw new ValidationException(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE);
        }
    }
}
