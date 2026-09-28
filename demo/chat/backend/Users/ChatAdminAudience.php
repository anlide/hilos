<?php

declare(strict_types=1);

namespace Demo\Chat\Users;

use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Users\AdminAudience;

/**
 * ChatAdminAudience - the chat demo's administrators (HIL-279).
 *
 * Who says admin and who is blocked is the framework's answer, by the same `hilos_user` flags
 * the page-level gate reads. The chat adds one thing on top: an account merged into a survivor
 * is no reader either. Merging lives in the chat's own column until it has a framework table of
 * its own (HIL-1199), and so does this narrowing.
 */
final class ChatAdminAudience extends AdminAudience
{
    /**
     * The framework's administrators, less the accounts merged into a survivor.
     *
     * A merged account already carries a block ({@see UserActions::tombstone()}), which keeps it
     * out of the framework's answer; it is left out here as well because an administrator may
     * lift that block, and the row would then say admin again for a person who is somebody else
     * by now.
     *
     * @return list<int> Durable user ids of the unblocked, unmerged admins
     * @throws DatabaseException When loading the user collection fails
     * @throws LogicException When the user collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match the collection
     */
    protected static function userIds(): array
    {
        $userIds = [];
        foreach (parent::userIds() as $userId) {
            if (Hilos::$db->users[$userId]?->mergedInto === null) {
                $userIds[] = $userId;
            }
        }

        return $userIds;
    }
}
