<?php

namespace Demo\WebSocketTest\Database;

use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
use Demo\WebSocketTest\Database\ObjectCollection\UserSettings as ObjectUserSettings;
use Hilos\Database\Idea\IdeaStorage as BaseIdeaStorage;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;

/**
 * IdeaStorage for WebSocket Test Demo
 * Manages all Object collections with lazy loading strategies
 */
final class IdeaStorage extends BaseIdeaStorage
{
    /** @var ObjectUsers */
    public ObjectUsers $users;

    /** @var ObjectUserSettings */
    public ObjectUserSettings $userSettings;

    /**
     * Initialize storage with all collections
     *
     * @return static
     * @throws DatabaseException
     */
    public static function init(): static
    {
        $self = new self();

        // Users - LAZY_STRATEGY_KEY (never load all, only by key)
        $self->users = ObjectUsers::initPartialDB(Objects::LAZY_STRATEGY_KEY);

        // UserSettings - LAZY_STRATEGY_KEY (never load all, only by key)
        $self->userSettings = ObjectUserSettings::initPartialDB(Objects::LAZY_STRATEGY_KEY);

        return $self;
    }

    /**
     * Reload all collections from database
     *
     * @throws DatabaseException
     */
    public function initAgain(): void
    {
        $this->users->initAgainFullDB();
        $this->userSettings->initAgainFullDB();
    }

    /**
     * Reload specific collection (for write threads)
     *
     * @param string $collectionName Collection property name
     * @throws DatabaseException
     */
    public function reloadCollection(string $collectionName): void
    {
        match($collectionName) {
            'users' => $this->users->initAgainFullDB(),
            'userSettings' => $this->userSettings->initAgainFullDB(),
            default => throw new DatabaseException("Unknown collection: {$collectionName}"),
        };
    }
}
