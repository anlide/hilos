<?php

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * User Db item - high-level abstraction with lazy loading and relationships.
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
     * @return int|string|Connections|null Property value or Connections for relationships
     * @throws PropertyNotFoundException If property does not exist
     */
    public function __get(string $name): int|string|Connections|null
    {
        return match ($name) {
            ObjectUser::id => $this->_object->id,
            ObjectUser::name => $this->_object->name,
            ObjectUser::sessionToken => $this->_object->sessionToken,
            ObjectUser::lastActivity => $this->_object->lastActivity,
            RtChatContext::connections => Hilos::$rt->connections->forUser($this->id),
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
        }

        return $result;
    }
}
