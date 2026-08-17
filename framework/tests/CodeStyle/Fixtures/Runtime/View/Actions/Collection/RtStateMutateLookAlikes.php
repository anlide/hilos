<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Runtime\View\Actions\Collection;

use Hilos\Runtime\State\Item\RtState;

/**
 * Negative sample of what the rule is obliged to let through, on the path where it is
 * strictest. Each method below reads like a mutation of the backing collection and is
 * not one, so a single reported line here means the rule has grown wider than the
 * document it enforces.
 */
final class RtStateMutateLookAlikes
{
    /**
     * A detached copy is filled by its owner: the rows are the same objects, but the
     * copy is not mounted under a collection name, so it is a read surface nobody
     * subscribes to. Banning this write would ban the construction of a filtered view.
     *
     * @param RtState $state Row put into the copy
     * @return object The filtered copy
     */
    public function detachedCopy(RtState $state): object
    {
        $collection = $this->getStateCollection();
        $copy = $collection::init();
        $copy->add($state);

        return $copy;
    }

    /**
     * @param string $id Key to look up
     * @return array<int, mixed> Whatever the collection answers, read and never written
     */
    public function reads(string $id): array
    {
        $collection = $this->getStateCollection();
        $rows = [];
        foreach ($collection as $row) {
            $rows[] = $row;
        }

        return [$collection->get($id), $collection->has($id), $collection[$id], $rows];
    }

    /**
     * A field of a row that is already in the collection: membership does not change,
     * and the write travels the item's own sync.
     *
     * @param string $id Key of the row to write a field of
     */
    public function rowField(string $id): void
    {
        $this->stateCollection[$id]->moderationMessage = 'held';
    }

    /**
     * A method of this class that happens to carry a mutating name. Declaring it is not
     * calling it, and the receiver is not the backing collection either way.
     *
     * @param RtState $state Row this class is asked to take
     */
    public function add(RtState $state): void
    {
        $this->taken[] = $state;
    }
}
