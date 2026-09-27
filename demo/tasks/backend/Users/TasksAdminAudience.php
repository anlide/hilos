<?php

declare(strict_types=1);

namespace Demo\Tasks\Users;

use Demo\Tasks\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Users\AdminAudience;

/** The demo's active administrators, for write authorization and administrator notifications. */
final class TasksAdminAudience extends AdminAudience
{
    /**
     * @return list<int> Durable ids of administrators whose accounts are not blocked
     * @throws DatabaseException When the users cannot be loaded
     * @throws LogicException When the collection constants are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    protected static function userIds(): array
    {
        $userIds = [];
        foreach (Hilos::$db->users->listAll() as $user) {
            if ($user->id !== null && $user->admin === true && $user->block !== true) {
                $userIds[] = $user->id;
            }
        }

        return $userIds;
    }
}
