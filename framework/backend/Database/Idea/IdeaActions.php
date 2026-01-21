<?php

namespace Hilos\Database\Idea;

use Hilos\Database\Idea\IdeaItem;
use Hilos\Database\Object\Object_;
use RuntimeException;

/**
 * Base class for Idea Actions
 * Provides write operations (mutations) for Idea collections
 *
 * Actions are used to perform write operations on collections.
 * Each IdeaCollection can have its own Actions class with collection-specific methods.
 *
 * Usage:
 *   $user = Idea::$idea->users->actions->register($sessionToken);
 *   $event = Idea::$idea->events->actions->add($type, $userId, $data);
 *
 * @property-read IdeaCollection $collection IdeaCollection instance this actions belong to
 */
abstract class IdeaActions
{
    /**
     * IdeaCollection instance this actions belong to
     * Type is declared via @property-read in child classes
     *
     * @var IdeaCollection
     */
    protected IdeaCollection $collection;

    /**
     * Callback for creating IdeaItem from Object
     * Set by IdeaCollection via setCreateIdeaCallback()
     *
     * @var callable(Object_): IdeaItem|null
     */
    private $createIdeaCallback = null;

    /**
     * Constructor
     *
     * @param IdeaCollection $collection IdeaCollection instance
     */
    public function __construct(IdeaCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Set callback for creating IdeaItem from Object
     * Called by IdeaCollection when Actions is created
     *
     * @param callable(Object_): IdeaItem $callback Callback function
     */
    public function setCreateIdeaCallback(callable $callback): void
    {
        $this->createIdeaCallback = $callback;
    }

    /**
     * Create IdeaItem from Object using callback
     * 
     * @param Object_ $object Object instance
     * @return IdeaItem
     * @throws RuntimeException If callback is not set
     */
    protected function createIdeaFromObject(Object_ &$object): IdeaItem
    {
        if ($this->createIdeaCallback === null) {
            throw new RuntimeException("createIdeaCallback is not set. IdeaCollection must call setCreateIdeaCallback() when creating Actions.");
        }
        
        return ($this->createIdeaCallback)($object);
    }
}
