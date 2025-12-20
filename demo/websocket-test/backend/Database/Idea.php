<?php

namespace Demo\WebSocketTest\Database;

use Demo\WebSocketTest\Database\IdeaCollection\Users as IdeaUsers;
use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Exception\DatabaseException;

/**
 * Idea - Static access point for read-only data access
 *
 * Usage:
 *   Idea::init(true); // Initialize with IdeaStorage
 *   $user = Idea::$idea->users[123]; // Get User idea
 *   $users = Idea::$idea->users; // Get Users collection
 */
final class Idea
{
    public const string users = 'users';

    /** @var self|null Singleton instance */
    public static ?self $idea = null;

    /** @var IdeaStorage|null Storage instance */
    public static ?IdeaStorage $storage = null;

    /** @var ObjectUsers */
    private ObjectUsers $_objectUsers;

    /** @var IdeaUsers */
    private IdeaUsers $_collectionIdeaUsers;

    /**
     * Private constructor - use init() instead
     */
    private function __construct()
    {
    }

    /**
     * Private clone - prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Initialize Idea with storage
     *
     * @param bool $withStorage If true, initialize IdeaStorage
     * @throws DatabaseException
     */
    public static function init(bool $withStorage = false): void
    {
        if (self::$idea === null) {
            self::$idea = new self();
        }

        if ($withStorage && self::$storage === null) {
            self::$storage = IdeaStorage::init();

            self::$idea->setRepresentUsers(self::$storage->users);
        }
    }

    /**
     * Set representation for Users Object collection
     *
     * @param ObjectUsers $users
     */
    public function setRepresentUsers(ObjectUsers &$users): void
    {
        $this->_objectUsers = &$users;
        $this->_collectionIdeaUsers = IdeaUsers::init($users);
    }

    /**
     * Get Idea collection by name
     *
     * @param string $name Collection name
     * @return IdeaUsers
     * @throws DatabaseException
     */
    public function __get(string $name): IdeaUsers
    {
        return match ($name) {
            self::users => $this->_collectionIdeaUsers,
            default => throw new DatabaseException("Idea collection [{$name}] does not exist"),
        };
    }

    /**
     * Convert all collections to array
     *
     * @return array<string, array>
     */
    public function toArray(): array
    {
        return [
            self::users => $this->_collectionIdeaUsers->toArray(),
        ];
    }
}
