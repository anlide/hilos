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
 * Refuses a person who is gone, folded into someone else, or whose erasure has begun.
 *
 * The one copy of that check on the way to the person's agent. A coordinator calls it before the
 * hop, so a late frame never raises the agent. The agent calls it again on the race where the hop
 * left before the erasure, the merge or the start of the erasure arrived. People, their merges and
 * their deletion requests are read by every process, so the check needs no read claim of its own.
 *
 * The checks run in that order, and the first that holds answers: a merged account whose erasure
 * has begun is refused in the words of the merge (HIL-1939). Carrying on with an operation of the
 * person's own goes through that operation's participant step, not through this check: there is
 * no common way around it, neither for a merged account nor for a missing one.
 */
final class AddressablePerson
{
    /**
     * @param int $userId Person a frame would be addressed to
     * @throws ItemNotFoundForUpdateException When there is no such person
     * @throws ValidationException When the account was merged into another one, or its erasure has begun
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a stored row is not the collection's object type
     * @throws DatabaseException When a row cannot be loaded
     */
    public static function require(int $userId): void
    {
        if (!isset(Hilos::$db->users[$userId])) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }
        if (isset(Hilos::$db->userMerges[$userId])) {
            throw new ValidationException(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE);
        }
        if (Hilos::$db->accountDeletions->startedOf($userId) !== null) {
            throw new ValidationException(AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE);
        }
    }
}
