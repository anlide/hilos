<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Group\GroupMembership;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;

/** The per-person group for changes to a data export. */
final class DataExportGroup
{
    /** Name the group answers to, and the head of every full name. */
    public const string NAME = 'hilos_data_export';

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
     * Replaces this connection's export membership in the worker mirror and the master's fan-out list.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param int $userId Person the connection may export for
     * @param SignalSourceInterface $source Agent admitting this membership
     * @throws InvalidArgumentException When the join announcement cannot be named
     */
    public static function join(string $acceptKey, int $userId, SignalSourceInterface $source): void
    {
        $group = self::forUser($userId);
        if (Hilos::$sr?->groupSubscriptionName($acceptKey, self::NAME) === $group) {
            return;
        }
        self::leave($acceptKey, $source);
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

    /**
     * Removes memberships belonging to a block notice the browser no longer holds.
     *
     * The end of the "Access closed" card's membership, said when an anonymous tab is answered
     * without the card ({@see AbstractAgent::sendHandshakeResponse()}): closing the card changes
     * no person, so the rule that a change of person drops every group of a connection
     * ({@see GroupMembership::leaveAll()}, HIL-1284) does not cover it.
     *
     * @param string $acceptKey Connection leaving its previous export group
     * @param SignalSourceInterface $source Session holder announcing the leave
     * @throws InvalidArgumentException When the unsubscribe signal cannot be named
     */
    public static function leave(string $acceptKey, SignalSourceInterface $source): void
    {
        while (($group = Hilos::$sr?->groupSubscriptionName($acceptKey, self::NAME)) !== null) {
            $leave = new WebSocketGroupUnsubscribeSignalDTO($acceptKey, $group);
            Hilos::$sr->unsubscribeFromGroup($group, $leave);
            Hilos::$sr->queueSignal(
                signalSource: $source,
                signalType: new SignalType(SignalTypeConstants::GROUP_UNSUBSCRIBE),
                signalName: new SignalName($group),
                signalData: $leave,
            );
        }
    }

}
