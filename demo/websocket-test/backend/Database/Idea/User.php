<?php

namespace Demo\WebSocketTest\Database\Idea;

use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Exception;
use Hilos\Database\Idea\IdeaItem;
use RuntimeException;

/**
 * User Idea
 * High-level abstraction with lazy loading and relationships
 * 
 * Stores reference to ObjectUser instance.
 * Object instances are stored in ObjectCollection in IdeaStorage.
 *
 * @property-read ?int $id User ID (primary key)
 * @property-read string $name User name
 * @property-read ?string $theme User theme preference
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
     * Get ObjectUser instance
     * Returns the underlying ObjectUser instance that this IdeaUser wraps.
     * This is a reference to the Object stored in ObjectCollection in IdeaStorage.
     * 
     * @return ObjectUser ObjectUser instance (reference, not a copy)
     * @throws RuntimeException If the underlying object is not an ObjectUser instance
     */
    private function getObjectUser(): ObjectUser
    {
        $object = $this->getObject();
        if (!($object instanceof ObjectUser)) {
            throw new RuntimeException("Expected ObjectUser instance");
        }
        return $object;
    }

    /**
     * Property getter (read-only access)
     * Provides access to ObjectUser properties through IdeaUser interface.
     * Supports lazy loading of related collections (see examples in comments).
     *
     * @param string $name Property name (id, name, theme, sessionToken, lastActivity, or related collection name)
     * @return int|string|null Property value or IdeaCollection for relationships
     * @throws Exception If property does not exist
     */
    public function __get(string $name): int|string|null
    {
        $objectUser = $this->getObjectUser();

        return match ($name) {
            ObjectUser::id => $objectUser->id,
            ObjectUser::name => $objectUser->name,
            ObjectUser::theme => $objectUser->theme,
            ObjectUser::sessionToken => $objectUser->sessionToken,
            ObjectUser::lastActivity => $objectUser->lastActivity,

            // Example of lazy loading relationships:
            // 'orders' => $this->getOrders(),
            // 'posts' => $this->getPosts(),

            default => throw new Exception("Property [{$name}] does not exist on IdeaUser"),
        };
    }

    /**
     * Convert IdeaUser to array representation
     * Extracts data from underlying ObjectUser instance.
     *
     * @param bool $withId Include ID field in result
     * @param bool $idAsIndex Use ID as array key (ignored if $withId is false)
     * @param bool $withBridges Include bridge/junction table data (not used for User)
     * @param bool $withCalculation Include calculated fields (not used for User)
     * @return array<string, mixed> Array representation of user data
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $objectUser = $this->getObjectUser();

        $data = [];

        if ($withId) {
            $data[ObjectUser::id] = $objectUser->id;
        }

        $data[ObjectUser::name] = $objectUser->name;
        $data[ObjectUser::theme] = $objectUser->theme;
        $data[ObjectUser::sessionToken] = $objectUser->sessionToken;
        $data[ObjectUser::lastActivity] = $objectUser->lastActivity;

        return $data;
    }
}
