<?php

namespace Demo\WebSocketTest\Database;

use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Database\Idea\IdeaStorage as BaseIdeaStorage;
use Hilos\Database\Object\Objects;
use Demo\WebSocketTest\Database\ObjectCollection\Events as ObjectEvents;
use Hilos\Exception\DatabaseException;

/**
 * IdeaStorage for WebSocket Test Demo
 * Manages all Object collections with lazy loading strategies
 */
final class IdeaStorage extends BaseIdeaStorage
{
    /** @var ObjectUsers */
    public ObjectUsers $users;

    /** @var ObjectEvents */
    public ObjectEvents $events;

    /**
     * Initialize storage with all collections
     *
     * @return IdeaStorage
     * @throws DatabaseException
     */
    public static function init(): static
    {
        $self = new self();

        // Users - LAZY_STRATEGY_KEY (never load all, only by key)
        $self->users = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        // Events - LAZY_STRATEGY_NONE (load all immediately, no lazy loading)
        $self->events = ObjectEvents::initDB(Objects::LAZY_STRATEGY_NONE);

        return $self;
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
            'users' => $this->users->loadAllFromDB(),
            'events' => $this->events->loadAllFromDB(),
            default => throw new DatabaseException("Unknown collection: {$collectionName}"),
        };
    }

    /**
     * Clear all events from database and collection
     * Lazy load will reload events on next access
     *
     * @throws DatabaseException
     */
    public function clearEvents(): void
    {
        $this->events->deleteAll();
    }
}
