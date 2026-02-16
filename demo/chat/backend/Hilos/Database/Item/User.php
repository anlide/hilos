<?php

namespace Demo\Chat\Hilos\Database\Item;

use Demo\Chat\Database\Object\User as ObjectUser;
use Demo\Chat\Hilos\Database\Hilos;
use Demo\Chat\Hilos\Runtime\Collection\Connections;
use Hilos\Hilos\Database\Item\DbItem;
use RuntimeException;

/**
 * User Db item - high-level abstraction with lazy loading and relationships.
 *
 * Stores reference to ObjectUser instance.
 * Object instances are stored in ObjectCollection in Hilos.
 *
 * @extends DbItem<ObjectUser>
 *
 * @property-read ?int $id User ID (primary key)
 * @property-read string $name User name
 * @property-read ?string $sessionToken User session token (32 hex characters)
 * @property-read ?string $lastActivity Last activity timestamp
 * @property-read Connections $connections Connections for this user (online check)
 */
final class User extends DbItem
{
    /**
     * Creates User from ObjectUser instance.
     *
     * @param ObjectUser $objectUser ObjectUser instance (reference)
     */
    public function __construct(ObjectUser &$objectUser)
    {
        parent::__construct($objectUser);
    }

    /**
     * Property getter (read-only access). Supports lazy loading of related collections.
     *
     * @param string $name Property name
     * @return int|string|Connections|null Property value or Connections for relationships
     * @throws RuntimeException If property does not exist
     */
    public function __get(string $name): int|string|Connections|null
    {
        return match ($name) {
            ObjectUser::id => $this->_object->id,
            ObjectUser::name => $this->_object->name,
            ObjectUser::sessionToken => $this->_object->sessionToken,
            ObjectUser::lastActivity => $this->_object->lastActivity,
            Hilos::connections => Hilos::$rt->connections->forUser($this->id),
            default => parent::__get($name),
        };
    }

    /**
     * Convert to array representation.
     *
     * @param bool $withId Include ID field in result
     * @param bool $idAsIndex Use ID as array key
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend When true, exclude sessionToken (must not be sent to frontend)
     * @return array<string, mixed> Array representation
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectUser::id] = $this->_object->id;
        }

        $data[ObjectUser::name] = $this->_object->name;
        if (!$toFrontend) {
            $data[ObjectUser::sessionToken] = $this->_object->sessionToken;
        }
        $data[ObjectUser::lastActivity] = $this->_object->lastActivity;
        if ($toFrontend) {
            $data['presence'] = count($this->connections) > 0 ? 'online' : 'offline';
        }

        return $data;
    }
}
