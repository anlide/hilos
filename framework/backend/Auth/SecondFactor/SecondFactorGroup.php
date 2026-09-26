<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

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
 * SecondFactorGroup - the per-person WebSocket group the profile's second-factor section listens on (HIL-494).
 *
 * The person's connections that show the section are addressed by group name, the way the
 * notification center addresses a recipient: the connection joins when it subscribes to the
 * profile or its security page, and every write to the person's second factor - from any tab, any
 * browser, or the removal sweep - is fanned here as the section's new state
 * ({@see HilosSignalConstants::HILOS_SECOND_FACTOR_STATE}).
 */
final class SecondFactorGroup
{
    /** Name the group answers to, and the head of every full name. */
    public const string NAME = 'hilos_second_factor';

    /** Group-name prefix; the person's user id is appended. */
    public const string PREFIX = self::NAME . ':';

    /**
     * Builds the group name of a person.
     *
     * @param int $userId Person whose section it is
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
     * every profile page carrying the section, once its subscription is answered.
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
