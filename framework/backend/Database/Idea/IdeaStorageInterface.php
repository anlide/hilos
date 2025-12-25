<?php

namespace Hilos\Database\Idea;

use Hilos\Exception\DatabaseException;

/**
 * IdeaStorageInterface
 * 
 * Interface for IdeaStorage implementations.
 * Provides type-safe contract for storage initialization.
 * 
 * @template TStorage of IdeaStorage
 */
interface IdeaStorageInterface
{
    /**
     * Initialize storage with all collections
     * 
     * @return TStorage
     * @throws DatabaseException
     */
    public static function init(): static;

    /**
     * Reload all collections from database
     * 
     * @throws DatabaseException
     */
    public function initAgain(): void;

    /**
     * Reload specific collection (for write threads)
     * 
     * @param string $collectionName Collection property name
     * @throws DatabaseException
     */
    public function reloadCollection(string $collectionName): void;
}

