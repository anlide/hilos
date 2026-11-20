<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Constants;

/**
 * ProductAvailability - how much of a product of the shop's catalog there is to sell.
 *
 * Read from the product's kind and its units in all warehouses; nothing stores it. A stock item
 * is in stock, low or out of stock by its units; a unique piece is free once it is in a warehouse
 * and not placed until then.
 */
enum ProductAvailability: string
{
    /** Most units a stock item has while it is still low; one threshold for the admin and the storefront. */
    public const int LOW_UNITS_MAX = 5;

    /** Stock item with more than {@see self::LOW_UNITS_MAX} units */
    case IN_STOCK = 'in_stock';

    /** Stock item with one to {@see self::LOW_UNITS_MAX} units */
    case LOW = 'low';

    /** Stock item with no units in any warehouse */
    case OUT_OF_STOCK = 'out_of_stock';

    /** Unique piece kept in a warehouse */
    case FREE = 'free';

    /** Unique piece not yet in any warehouse */
    case NOT_PLACED = 'not_placed';

    /**
     * Tells the availability of a product of this kind with this many units.
     *
     * @param ProductKind $kind Kind of the product
     * @param int $units Its units in all warehouses summed
     * @return self Availability of the product
     */
    public static function of(ProductKind $kind, int $units): self
    {
        if ($kind === ProductKind::UNIQUE) {
            return $units > 0 ? self::FREE : self::NOT_PLACED;
        }
        if ($units === 0) {
            return self::OUT_OF_STOCK;
        }

        return $units <= self::LOW_UNITS_MAX ? self::LOW : self::IN_STOCK;
    }
}
