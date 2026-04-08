<?php

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Runtime\View\Item\ChatUserState as RuntimeChatUserState;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * User - Db item with high-level abstraction and lazy loading.
 *
 * Stores reference to ObjectUser instance.
 * Object instances are stored in ObjectCollection in Hilos.
 *
 * @extends DbItem<ObjectUser>
 * @method __construct(ObjectUser &$objectUser)
 *
 * @property-read ?int $id User ID (primary key)
 * @property-read string $name User name
 * @property-read ?string $sessionToken User session token (32 hex characters)
 * @property-read ?string $lastActivity Last activity timestamp
 * @property-read Connections $connections Connections for this user (online check)
 * @property-read ?RuntimeChatUserState $chatUserState Per-user chat runtime state (moderation, files)
 * @property-read UserActions $actions Actions for write operations on this user
 */
final class User extends DbItem
{
    private const string PRESENCE_KEY = 'presence';
    private const string PRESENCE_ONLINE = 'online';
    private const string PRESENCE_OFFLINE = 'offline';

    /**
     * Property getter (read-only access). Supports lazy loading of related collections.
     *
     * @param string $name Property name
     * @return int|string|Connections|RuntimeChatUserState|UserActions|null Property value, actions, or linked runtime items
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If actions class is not defined or cannot be instantiated
     */
    public function __get(string $name): int|string|Connections|RuntimeChatUserState|UserActions|null
    {
        return match ($name) {
            ObjectUser::id => $this->_object->id,
            ObjectUser::name => $this->_object->name,
            ObjectUser::sessionToken => $this->_object->sessionToken,
            ObjectUser::lastActivity => $this->_object->lastActivity,
            RtChatContext::connections => Hilos::$rt->connections->forUser($this->id),
            RtChatContext::chatUserState => $this->_object->id !== null
                ? Hilos::$rt->userStates->actions->ensure((int)$this->_object->id)
                : null,
            default => parent::__get($name),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $result = parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);

        if ($toFrontend) {
            unset($result[ObjectUser::sessionToken]);
            $result[self::PRESENCE_KEY] = count($this->connections) > 0 ? self::PRESENCE_ONLINE : self::PRESENCE_OFFLINE;
            // chatUserState is private - sent via handshake and MODERATION_STATE_UPDATE only
        }

        return $result;
    }
}
