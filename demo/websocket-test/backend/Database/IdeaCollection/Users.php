<?php

namespace Demo\WebSocketTest\Database\IdeaCollection;

use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\ObjectCollection;

/**
 * Users Idea Collection
 * Collection of User ideas with additional filtering methods
 */
final class Users extends IdeaCollection
{
    /**
     * Initialize collection from objects
     */
    public static function init(ObjectCollection &$objects): self
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
     * Filter admins only
     */
    public function filterAdmins(): self
    {
        return $this->filter(function(ObjectUser $user) {
            return $user->admin === true;
        });
    }

    /**
     * Filter non-blocked users
     */
    public function filterActive(): self
    {
        return $this->filter(function(ObjectUser $user) {
            return $user->block === false;
        });
    }

    /**
     * Get users marked for deletion
     */
    public function filterMarkedForDeletion(): self
    {
        return $this->filter(function(ObjectUser $user) {
            return $user->willDelete !== null;
        });
    }

    /**
     * Convert to array with additional options
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        return parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation);
    }
}

