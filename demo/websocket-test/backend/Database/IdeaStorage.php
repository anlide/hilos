<?php

namespace Demo\WebSocketTest\Database;

use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
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

        // TODO: Add other collections as needed
        // Example for Settings (full load on access):
        // $self->settings = ObjectSettings::initPartialDB(Objects::LAZY_STRATEGY_FULL_ON_ACCESS);

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

        // TODO: Add other collections as needed
        // Example:
        // $this->orders->initAgainFullDB();
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
            default => throw new DatabaseException("Unknown collection: {$collectionName}"),
        };
    }
}
