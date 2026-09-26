<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;

/**
 * AccountDeletionGroup - the per-person WebSocket group the account deletion surface listens on (HIL-302).
 *
 * The person's connections that show the danger zone join it when they subscribe to the
 * profile page that draws the zone, and every start and cancel - from any tab, any browser -
 * is fanned here as the person's new state ({@see HilosSignalConstants::HILOS_ACCOUNT_DELETION_STATE}).
 */
final class AccountDeletionGroup
{
    /** Name the group answers to, and the head of every full name. */
    public const string NAME = 'hilos_account_deletion';

    /** Group-name prefix; the person's user id is appended. */
    public const string PREFIX = self::NAME . ':';

    /**
     * Builds the group name of a person.
     *
     * @param int $userId Person whose surface it is
     * @return string Group name the person's connections join
     */
    public static function forUser(int $userId): string
    {
        return self::PREFIX . $userId;
    }

    /**
     * Puts one connection on the person's group.
     *
     * The same two writes a group's own join makes: the membership in this worker's mirror, and
     * the word to the master, which keeps the fan-out list every process sends through. Asked by
     * every profile page that draws the zone, once its subscription is answered.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param int $userId Person the connection is signed in as
     * @param SignalSourceInterface $source Signal source of the page's owning agent
     * @throws InvalidArgumentException When the join announcement cannot be named
     */
    public static function join(string $acceptKey, int $userId, SignalSourceInterface $source): void
    {
        $group = self::forUser($userId);
        Hilos::$sr?->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(
            acceptKey: $acceptKey,
            group: $group,
            params: [],
        ));
        Hilos::$sr?->queueSignal(
            signalSource: $source,
            signalType: new SignalType(SignalTypeConstants::GROUP_JOIN),
            signalName: new SignalName(SignalTypeConstants::GROUP_JOIN),
            signalData: new GroupJoinSignalData($group, $acceptKey, []),
        );
    }
}
