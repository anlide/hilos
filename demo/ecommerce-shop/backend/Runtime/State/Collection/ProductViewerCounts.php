<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Runtime\State\Collection;

use Demo\EcommerceShop\Runtime\State\Item\ProductViewerCount;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * Live viewer counts keyed by storefront product slug.
 *
 * @extends RtStates<ProductViewerCount>
 */
final class ProductViewerCounts extends RtStates
{
    public const string STATE_CLASS = ProductViewerCount::class;

    /**
     * @param ?string $id Product slug, or null for a missing optional runtime key
     * @return ?ProductViewerCount Product viewer count, or null when missing
     */
    public function get(?string $id): ?ProductViewerCount
    {
        /** @var ?ProductViewerCount $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Product slug
     * @return ProductViewerCount Product viewer count
     * @throws OutOfBoundsException When the requested state row is absent
     */
    public function offsetGet(mixed $offset): ProductViewerCount
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Product viewer count not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Product viewer count not found: {$offset}");
    }
}
