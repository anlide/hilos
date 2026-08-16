<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Subscriber;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;

/**
 * Drops the one cached wrapper a membership change invalidated.
 *
 * A collection answers reads out of a cache of view wrappers keyed by row id, so a key that
 * left the store keeps answering as alive and a key that came back answers with the wrapper
 * around the row it no longer holds. Both are cured by forgetting exactly that key.
 *
 * The view is addressed through the context by the name carried in the fact, not through the
 * mutated store, so the same subscriber serves the road through the collection actions and the
 * road through an applicator, which knows nothing but the name.
 *
 * Provenance is deliberately ignored: a view holding a row the store lost is equally wrong
 * whether this process wrote the change or applied someone else's.
 */
final class ViewCacheSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * Forgets the cached wrapper of the row the change created or deleted.
     *
     * An update is not a membership change: the wrapper still points at the very state the
     * diff was written into, so dropping it would only cost a rebuild.
     *
     * A collection with no view mounted, or a name the context does not know, is a silent
     * no-op - there is no cache to repair.
     *
     * @param SourceChange $change Fact describing what happened to the source
     * @param SourceChangeProvenance $provenance Ignored, see the class docblock
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->mutationType !== TableMutationType::Create
            && $change->mutationType !== TableMutationType::Delete
        ) {
            return;
        }

        if ($change->isRt()) {
            Hilos::$rt?->getRtCollection($change->sourceKey)?->forgetCachedItem($change->sourceId);

            return;
        }

        Hilos::$db?->getDbItemCollection($change->sourceKey)?->forgetCachedItem($change->sourceId);
    }
}
