<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Pages\AdminProductPage;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/**
 * AdminProductPageSubscribeParams - Typed subscribe params for one product's admin page.
 *
 * Parsed from the raw route params by {@see AdminProductPage::onSubscribeBeforeResponse()}. Guarantees
 * a positive `productId` read from the `productId` route segment (`/hilos/app/product/{productId}`).
 * Whether the number names a product is not this DTO's question.
 */
final class AdminProductPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /** @var string Route param key mirroring the route segment name `productId`. */
    public const string PRODUCT_ID = 'productId';

    /**
     * @param int $productId The product's id, always positive
     */
    public function __construct(public readonly int $productId)
    {
    }

    /**
     * Reads the `productId` route param as a positive integer.
     *
     * @param PageRouteParams $params Raw subscribe params
     * @throws MissingPageRouteParamException When `productId` is absent or empty
     * @throws InvalidPageRouteParamException When `productId` is not a positive integer
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(
            productId: $params->requirePositiveInt(self::PRODUCT_ID),
        );
    }
}
