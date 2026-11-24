<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Runtime\View\Context;

use Demo\EcommerceShop\Runtime\State\Collection\Connections as StateConnections;
use Demo\EcommerceShop\Runtime\State\Collection\ProductViewerCounts as StateProductViewerCounts;
use Demo\EcommerceShop\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\EcommerceShop\Runtime\View\Actions\Collection\ProductViewerCountsActions;
use Demo\EcommerceShop\Runtime\View\Actions\Item\ConnectionActions;
use Demo\EcommerceShop\Runtime\View\Actions\Item\ProductViewerCountActions;
use Demo\EcommerceShop\Runtime\View\Collection\Connections;
use Demo\EcommerceShop\Runtime\View\Collection\ProductViewerCounts;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;

/**
 * EcommerceShopRtContext - ecommerce-shop runtime context.
 *
 * Holds active WebSocket connections and the live viewer counts of storefront product pages.
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);
 *   Hilos::$rt->connections->summaryForUser($userId);
 *
 * @property-read Connections $connections Active connections collection
 * @property-read ProductViewerCounts $productViewerCounts Viewer counts by product slug
 */
final class EcommerceShopRtContext extends RtContext
{
    public const string connections = 'connections';
    public const string productViewerCounts = 'productViewerCounts';

    /**
     * Registers the runtime collections and their view representations.
     *
     * @throws StateCollectionNotFoundException When a represented collection key is not registered
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = StateConnections::init();
        $this->_stateCollections[self::productViewerCounts] = StateProductViewerCounts::init();

        $this->setRepresent(
            self::connections,
            Connections::class,
            ConnectionsActions::class,
            ConnectionActions::class,
        );
        $this->setRepresent(
            self::productViewerCounts,
            ProductViewerCounts::class,
            ProductViewerCountsActions::class,
            ProductViewerCountActions::class,
        );
    }
}
