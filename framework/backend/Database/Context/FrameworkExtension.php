<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Database\Actions\Collection\DbActions as CollectionDbActions;
use Hilos\Database\Actions\Item\DbActions as ItemDbActions;
use Hilos\Database\View\Collection\DbCollection;

/**
 * One declaration of a project: under this framework key, my chain.
 *
 * A project that needs a column of its own on a framework table subclasses the table's whole
 * chain and mounts it under the framework's own key, through
 * {@see HilosDbContext::frameworkExtensions()} (inheritance.md). This is what one entry of that
 * declaration carries: the project's view collection, and the project's two action layers where
 * the framework registers any for the key. The rest of the chain the view collection names
 * itself - OBJECT_COLLECTION_CLASS reaches the object collection, which reaches the Object, which
 * reaches the Entity, and DB_ITEM_CLASS the view item - so there is no second place the chain
 * is named from. The framework's loading strategy for the key stays the framework's: how a table
 * is read is the decision of the table's owner, not of the subclass.
 *
 * An action layer left null keeps the framework's class for that layer. A chain half-inherited
 * that way is what the start guard refuses (HIL-1191); the mount itself refuses only what the
 * declaration got wrong on its own - see FrameworkExtensionException.
 */
final readonly class FrameworkExtension
{
    /**
     * @param class-string<DbCollection> $collection The project's view collection, which names the rest of the chain
     * @param ?class-string<CollectionDbActions> $actions The project's collection actions, where the framework registers any
     * @param ?class-string<ItemDbActions> $itemActions The project's item actions, where the framework registers any
     */
    public function __construct(
        public string $collection,
        public ?string $actions = null,
        public ?string $itemActions = null,
    ) {
    }
}
