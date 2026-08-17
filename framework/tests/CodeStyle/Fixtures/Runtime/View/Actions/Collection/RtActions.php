<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Runtime\View\Actions\Collection;

use Hilos\Runtime\State\Item\RtState;

/**
 * Negative sample: the very same mutations, in the file the rule names as the base
 * actions. A rule that fired here would ban the road it tells every other caller to
 * take, so this file must report nothing.
 */
final class RtActions
{
    /**
     * @param RtState $state Row put into the collection
     * @param string $id Key of the row dropped
     */
    public function membership(RtState $state, string $id): void
    {
        $this->stateCollection->add($state);
        $this->getStateCollection()->remove($id);

        $collection = $this->getStateCollection();
        $collection->clear();
        $collection[$id] = $state;
        unset($collection[$id]);
    }
}
