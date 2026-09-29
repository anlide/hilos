<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\StepUp\StepUpGate;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionConnection;

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
        return self::administratorOn($acceptKey)->userId;
    }

    /**
     * Resolves the active administrator and requires their live confirmation of an operation (HIL-1275).
     *
     * The prologue of an administrator's action that takes something away from another person:
     * the same re-check as {@see self::of()}, then the step-up gate before a line of the action's
     * own work. It is the administrator of this browser who confirms, not the person on the card;
     * what the gate lets through without a confirmation - an operation switched off - is its own
     * decision.
     *
     * @param string $acceptKey Connection that asked for the change
     * @param string $operation Declared operation key the action belongs to
     * @return int Effective administrator user id
     * @throws ValidationException When the user is absent, impersonated or not an active administrator, the
     *     connection carries no browser session, or the confirmation is absent or expired
     * @throws InvalidArgumentException When the operation is not declared or a collection query is invalid
     * @throws LogicException When collection metadata is incomplete
     * @throws DatabaseException When the session, setting, proof or confirmation cannot be read
     * @throws SettingException When the step-up setting catalog or value is invalid
     * @throws HilosException When the runtime or the project's administrator list cannot be read
     */
    public static function confirmed(string $acceptKey, string $operation): int
    {
        $connection = self::administratorOn($acceptKey);
        if ($connection->sessionToken === null) {
            throw new ValidationException('User session not found');
        }
        new StepUpGate()->require($connection->sessionToken, $connection->userId, $operation);

        return $connection->userId;
    }

    /**
     * @param string $acceptKey Connection that asked for the change
     * @return HilosSessionConnection Connection of an active administrator, its user id set
     * @throws ValidationException When the user is absent, impersonated or not an active administrator
     * @throws HilosException When the runtime or the project's administrator list cannot be read
     */
    private static function administratorOn(string $acceptKey): HilosSessionConnection
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

        return $connection;
    }
}
