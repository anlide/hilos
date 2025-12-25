<?php

namespace Demo\WebSocketTest\Database\IdeaCollection;

use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Objects;

/**
 * Users Idea Collection
 * Collection of User ideas with additional filtering methods
 */
final class Users extends IdeaCollection
{
    /**
     * Initialize collection from objects
     */
    public static function init(Objects &$objects): self
    {
        return new self($objects);
    }

    /**
     * Convert object to idea
     */
    protected function objectToIdea(mixed $object): IdeaUser
    {
        if (!($object instanceof ObjectUser)) {
            throw new \InvalidArgumentException("Object must be instance of ObjectUser");
        }

        return IdeaUser::get($object);
    }

    /**
     * Filter by email domain
     */
    public function filterByEmailDomain(string $domain): self
    {
        return $this->filter(function(ObjectUser $user) use ($domain) {
            return str_ends_with($user->email, "@{$domain}");
        });
    }


    /**
     * Find user by session token
     * Returns Idea\User (read-only) or null
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return IdeaUser|null User idea or null if not found
     * @throws \Hilos\Exception\DatabaseException
     */
    public function findBySession(string $sessionToken): ?IdeaUser
    {
        if (empty($sessionToken)) {
            return null;
        }

        // Delegate to ObjectCollection
        // $this->objects is guaranteed to be ObjectUsers instance
        if (!($this->objects instanceof ObjectUsers)) {
            throw new \RuntimeException("Expected ObjectUsers instance");
        }

        $objectUser = $this->objects->findBySession($sessionToken);

        if ($objectUser === null) {
            return null;
        }

        // Convert to Idea
        return $this->objectToIdea($objectUser);
    }

    /**
     * Convert to array with additional options
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        return parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation);
    }
}
