<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Idea\IdeaItem;
use Hilos\Exception\Idea\Item\IdeaItemPropertyNotFoundException;
use RuntimeException;

/**
 * User Idea
 * High-level abstraction with lazy loading and relationships
 *
 * Stores reference to ObjectUser instance.
 * Object instances are stored in ObjectCollection in Idea.
 *
 * @extends IdeaItem<ObjectUser>
 *
 * @property-read ?int $id User ID (primary key)
 * @property-read string $name User name
 * @property-read ?string $sessionToken User session token (32 hex characters)
 * @property-read ?string $lastActivity Last activity timestamp
 */
final class User extends IdeaItem
{
    /**
     * Public constructor - creates IdeaUser from ObjectUser instance
     *
     * @param ObjectUser $objectUser ObjectUser instance (reference)
     */
    public function __construct(ObjectUser &$objectUser)
    {
        parent::__construct($objectUser);
    }

    /**
     * Property getter (read-only access)
     * Provides access to ObjectUser properties through IdeaUser interface.
     * Supports lazy loading of related collections.
     *
     * @param string $name Property name
     * @return int|string|null Property value or IdeaCollection for relationships
     * @throws RuntimeException If property does not exist
     * @throws IdeaItemPropertyNotFoundException
     */
    public function __get(string $name): int|string|null
    {
        return match ($name) {
            ObjectUser::id => $this->_object->id,
            ObjectUser::name => $this->_object->name,
            ObjectUser::sessionToken => $this->_object->sessionToken,
            ObjectUser::lastActivity => $this->_object->lastActivity,

            default => parent::__get($name),
        };
    }

    /**
     * Convert to array representation
     *
     * @param bool $withId Include ID field in result
     * @param bool $idAsIndex Use ID as array key
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @return array<string, mixed> Array representation
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectUser::id] = $this->_object->id;
        }

        $data[ObjectUser::name] = $this->_object->name;
        $data[ObjectUser::sessionToken] = $this->_object->sessionToken;
        $data[ObjectUser::lastActivity] = $this->_object->lastActivity;

        return $data;
    }
}
