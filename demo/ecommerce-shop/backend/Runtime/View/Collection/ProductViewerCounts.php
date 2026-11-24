<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Runtime\View\Collection;

use Demo\EcommerceShop\Runtime\State\Collection\ProductViewerCounts as StateProductViewerCounts;
use Demo\EcommerceShop\Runtime\State\Item\ProductViewerCount as StateProductViewerCount;
use Demo\EcommerceShop\Runtime\View\Actions\Collection\ProductViewerCountsActions;
use Demo\EcommerceShop\Runtime\View\Item\ProductViewerCount;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\HilosException;

/**
 * Read-only wrapper around live product viewer counts.
 *
 * @extends RtCollection<ProductViewerCount, ProductViewerCountsActions>
 * @property-read ProductViewerCountsActions $actions Actions for write operations
 */
final class ProductViewerCounts extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtCollectionNotReadableException When the runtime collection is unreadable
     */
    public function getStateCollection(): StateProductViewerCounts
    {
        /** @var StateProductViewerCounts */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateProductViewerCount instance
     * @return ProductViewerCount View item for this product count
     */
    protected function createRtItem(RtState $state): ProductViewerCount
    {
        /** @var StateProductViewerCount $state */
        return new ProductViewerCount($state);
    }

    /**
     * @param mixed $offset Product slug
     * @return ?ProductViewerCount Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtCollectionNotReadableException When the runtime collection is unreadable
     */
    public function offsetGet(mixed $offset): ?ProductViewerCount
    {
        /** @var ?ProductViewerCount $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return ProductViewerCountsActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): ProductViewerCountsActions
    {
        /** @var ProductViewerCountsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name to read
     * @return ProductViewerCountsActions Product viewer count actions
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException When the framework operation fails
     */
    public function __get(string $name): ProductViewerCountsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
