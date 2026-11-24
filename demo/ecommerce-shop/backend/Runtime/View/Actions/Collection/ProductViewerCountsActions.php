<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Runtime\View\Actions\Collection;

use Demo\EcommerceShop\Runtime\State\Collection\ProductViewerCounts as StateProductViewerCounts;
use Demo\EcommerceShop\Runtime\State\Item\ProductViewerCount as StateProductViewerCount;
use Demo\EcommerceShop\Runtime\View\Collection\ProductViewerCounts;
use Demo\EcommerceShop\Runtime\View\Item\ProductViewerCount as ViewProductViewerCount;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Collection\RtActions;

/**
 * Creation of positive viewer counts by the serving storefront agent.
 *
 * @extends RtActions<ViewProductViewerCount, ProductViewerCounts, StateProductViewerCounts>
 * @property-read StateProductViewerCounts $stateCollection
 */
final class ProductViewerCountsActions extends RtActions
{
    /**
     * @param string $slug Current storefront address
     * @param int $productId Product at that address
     * @param int $viewerCount Number of distinct viewers
     * @throws InvalidArgumentException When the state collection accessor is invalid
     * @throws InvalidFormatException When a row field is outside its domain
     * @throws LogicException When the address already has a row
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller does not own the row
     * @throws RtWriteBackpressureException When the ordinary FIFO has no room
     * @throws SourceChangeSubscriberException When a subscriber fails
     * @throws HilosException Whatever preparing the immutable operation raises
     */
    public function create(string $slug, int $productId, int $viewerCount): void
    {
        if ($this->getStateCollection()->get($slug) !== null) {
            throw new LogicException("Product viewer count already exists for {$slug}");
        }
        $this->addStateToCollection(StateProductViewerCount::create($slug, $productId, $viewerCount));
    }
}
