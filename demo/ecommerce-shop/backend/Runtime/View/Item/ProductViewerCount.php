<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Runtime\View\Item;

use Demo\EcommerceShop\Runtime\State\Item\ProductViewerCount as StateProductViewerCount;
use Demo\EcommerceShop\Runtime\View\Actions\Item\ProductViewerCountActions;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only storefront viewer count.
 *
 * @extends RtItem<StateProductViewerCount>
 * @property-read string $slug Current storefront address
 * @property-read int $productId Product at that address
 * @property-read int $viewerCount Number of distinct viewers
 * @property-read ProductViewerCountActions $actions Writes for this count
 */
final class ProductViewerCount extends RtItem
{
    /**
     * @param string $name Property to read
     * @return int|string|ProductViewerCountActions Requested property
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When the property is undeclared
     * @throws HilosException Whatever an inherited getter raises
     */
    public function __get(string $name): int|string|ProductViewerCountActions
    {
        return match ($name) {
            StateProductViewerCount::slug => $this->_state->slug,
            StateProductViewerCount::productId => $this->_state->productId,
            StateProductViewerCount::viewerCount => $this->_state->viewerCount,
            self::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /** @return array<string, mixed> Complete scalar row */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
