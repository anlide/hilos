<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Runtime\View\Actions\Collection;

use Hilos\Runtime\State\Item\RtState;

/**
 * Deliberately broken sample: a concrete actions class that changes the membership of
 * its backing collection by hand instead of calling the base method. Every receiver
 * the rule recognizes is exercised here, and the file does not stand on the path of a
 * legal writer, so every line below must be reported by RT-STATE-MUTATE.
 */
final class RtStateMutate
{
    /**
     * @param RtState $state Row put into the collection through the magic property
     * @param string $id Key of the row dropped through the magic property
     */
    public function throughProperty(RtState $state, string $id): void
    {
        $this->stateCollection->add($state);
        $this->stateCollection->remove($id);
        $this->stateCollection->clear();
    }

    /**
     * The backing property a view collection holds the same store under. A wrapper that
     * changed membership here would announce just as little as an actions class does.
     *
     * @param RtState $state Row put into the collection through the backing property
     * @param string $id Key of the row dropped through the backing property
     */
    public function throughBackingProperty(RtState $state, string $id): void
    {
        $this->_stateCollection->add($state);
        unset($this->_stateCollection[$id]);
    }

    /**
     * @param RtState $state Row put into the collection through the accessor
     * @param string $id Key of the row dropped through the accessor
     */
    public function throughAccessor(RtState $state, string $id): void
    {
        $this->getStateCollection()->add($state);
        $this->getStateCollection()->remove($id);
        $this->getStateCollection()->clear();
    }

    /**
     * @param RtState $state Row put into the collection through a local alias
     * @param string $id Key of the row dropped through a local alias
     */
    public function throughAlias(RtState $state, string $id): void
    {
        $collection = $this->getStateCollection();
        $collection->add($state);
        $collection->remove($id);
        $collection->clear();
        $collection[$id] = $state;
        unset($collection[$id]);
    }
}
