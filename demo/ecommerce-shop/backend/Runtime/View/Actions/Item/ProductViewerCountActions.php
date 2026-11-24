<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Runtime\View\Actions\Item;

use Demo\EcommerceShop\Runtime\State\Item\ProductViewerCount as StateProductViewerCount;
use Demo\EcommerceShop\Runtime\View\Item\ProductViewerCount as ViewProductViewerCount;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Updates and releases of a single product's viewer count.
 *
 * @extends RtActions<ViewProductViewerCount, StateProductViewerCount>
 * @property-read StateProductViewerCount $state
 */
final class ProductViewerCountActions extends RtActions
{
    /**
     * @param int $viewerCount New positive count
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller does not own the row
     * @throws RtWriteBackpressureException When the ordinary FIFO has no room
     * @throws HilosException Whatever validation or preparation of the RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function setViewerCount(int $viewerCount): void
    {
        $this->applyDiffWithSync([StateProductViewerCount::viewerCount => $viewerCount]);
    }

    /**
     * Removes the aggregate when the last viewer leaves.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When the item has no collection
     * @throws RtTruthSourceWriteNotAllowedException When caller does not own the row
     * @throws RtWriteBackpressureException When the ordinary FIFO has no room
     * @throws SourceChangeSubscriberException When a subscriber fails
     * @throws HilosException Whatever preparing the immutable operation raises
     */
    public function remove(): void
    {
        parent::remove();
    }

    /**
     * Releases a still-live row through the service reserve during agent stop.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When the item has no collection
     * @throws RtTruthSourceWriteNotAllowedException When caller does not own the row
     * @throws SourceChangeSubscriberException When a subscriber fails
     * @throws HilosException Whatever preparing or settling the immutable operation raises
     * @throws InvalidArgumentException When the retirement allowance is invalid
     */
    public function removeAsCleanup(): void
    {
        parent::removeAsCleanup();
    }
}
