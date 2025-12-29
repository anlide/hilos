<?php

namespace Demo\WebSocketTest\Database;

use Demo\WebSocketTest\Database\IdeaCollection\UserSettings as IdeaUserSettings;
use Demo\WebSocketTest\Database\IdeaCollection\Users as IdeaUsers;
use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Database\Idea\Idea as BaseIdea;
use Hilos\Exception\DatabaseException;

/**
 * Idea - Static access point for read-only data access
 *
 * Extends framework Idea class to provide application-specific initialization.
 *
 * Usage:
 *   Idea::init(true); // Initialize with IdeaStorage
 *   $user = Idea::$idea->users[123]; // Get User idea
 *   $users = Idea::$idea->users; // Get Users collection
 */
final class Idea extends BaseIdea
{
    public const string users = 'users';
    public const string userSettings = 'userSettings';

    /**
     * Initialize Idea with storage
     *
     * Overrides base class to create and configure IdeaStorage for this application.
     *
     * @param bool $withStorage If true, initialize IdeaStorage
     * @throws DatabaseException
     */
    public static function init(bool $withStorage = false): void
    {
        // Create singleton instance if not exists
        if (self::$idea === null) {
            self::$idea = new self();
        }

        // Initialize storage if requested and not already initialized
        if ($withStorage && self::$storage === null) {
            // Create application-specific IdeaStorage
            $storage = IdeaStorage::init();
            
            // Set storage using parent method
            self::setStorage($storage);

            // Configure collections using parent's setRepresent method
            self::$idea->setRepresent(
                name: self::users,
                objectCollection: $storage->users,
                ideaCollectionClass: IdeaUsers::class
            );

            self::$idea->setRepresent(
                name: self::userSettings,
                objectCollection: $storage->userSettings,
                ideaCollectionClass: IdeaUserSettings::class
            );
        }
    }
}
