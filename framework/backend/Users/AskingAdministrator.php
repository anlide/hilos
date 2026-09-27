<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Exception\ValidationException;
use Hilos\Hilos;
use Hilos\HilosException;

/** Resolves the active administrator again at the owning library's write boundary. */
final class AskingAdministrator
{
    /**
     * @param string $acceptKey Connection that asked for the change
     * @return int Effective administrator user id
     * @throws ValidationException When the user is absent, impersonated or not an active administrator
     * @throws HilosException When the runtime or the project's administrator list cannot be read
     */
    public static function of(string $acceptKey): int
    {
        $connection = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey);
        if ($connection?->userId === null) {
            throw new ValidationException('User session not found');
        }
        if (
            Hilos::$db->sessions[$connection->sessionId]?->impersonatorUserId !== null
            || !in_array($connection->userId, Hilos::adminAudienceClass()::all(), true)
        ) {
            throw new ValidationException('Only an active administrator can do this');
        }

        return $connection->userId;
    }
}
