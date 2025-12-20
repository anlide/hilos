<?php

namespace Hilos\Database\Idea;

use Hilos\Exception\DatabaseException;

/**
 * IdeaStorage
 * Central storage for all Object collections
 * Manages lazy loading strategies and provides access to collections
 *
 * This class should be extended in application-specific code to define
 * which collections to use and their lazy loading strategies
 */
abstract class IdeaStorage
{
    /**
     * Initialize storage with all collections
     * Should be overridden in child classes to define collections
     *
     * @return static
     * @throws DatabaseException
     */
    abstract public static function init(): static;

    /**
     * Reload all collections from database
     * Should be overridden in child classes
     *
     * @throws DatabaseException
     */
    abstract public function initAgain(): void;

    /**
     * Reload specific collection (for write threads)
     *
     * @param string $collectionName Collection property name
     * @throws DatabaseException
     */
    abstract public function reloadCollection(string $collectionName): void;

    /**
     * Private constructor - use init() instead
     */
    protected function __construct()
    {
    }

    /**
     * Private clone - prevent cloning
     */
    private function __clone()
    {
    }
}
