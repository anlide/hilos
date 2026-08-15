<?php

declare(strict_types=1);

namespace Demo\Chat\Users;

use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Users\AdminAudience;

/**
 * ChatAdminAudience - the chat demo's administrators (HIL-279).
 *
 * Answers from the durable user rows, by the same admin flag the page-level gate reads
 * ({@see ChatBrowserContext::isAdmin()}), so a person who can open the admin surface and a
 * person who hears from it are the same person.
 */
final class ChatAdminAudience extends AdminAudience
{
    /**
     * Blocked accounts and accounts merged into a survivor are left out: both keep a row
     * that still says admin, and neither is a reader who can act on what arrives.
     *
     * @return list<int> Durable user ids of the unblocked, unmerged admins
     * @throws DatabaseException When loading the user collection fails
     * @throws LogicException When the user collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match the collection
     */
    protected static function userIds(): array
    {
        $userIds = [];
        foreach (Hilos::$db->users->listAll() as $user) {
            if ($user->id === null || $user->admin !== true) {
                continue;
            }
            if ($user->block === true || $user->mergedInto !== null) {
                continue;
            }

            $userIds[] = $user->id;
        }

        return $userIds;
    }
}
