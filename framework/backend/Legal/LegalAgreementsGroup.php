<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;

/** Server-joined per-person group for acceptance state updates (HIL-498). */
final class LegalAgreementsGroup
{
    /** Name the group answers to, and the head of every full name. */
    public const string NAME = 'hilos_legal_agreements';

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
     * the authenticated profile pages after their subscriptions are answered.
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
