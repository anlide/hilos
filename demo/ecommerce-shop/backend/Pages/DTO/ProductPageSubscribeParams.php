<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Pages\ProductPage;
use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/**
 * ProductPageSubscribeParams - Typed subscribe params for the storefront's product page.
 *
 * Parsed from the raw route params by {@see ProductPage}. Guarantees a non-empty `slug`
 * read from the `slug` route segment (`/product/{slug}`).
 */
final class ProductPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    /** @var string Route param key mirroring the route segment name `slug`. */
    public const string SLUG = 'slug';

    /**
     * @param string $slug The product page address, never empty
     */
    public function __construct(public readonly string $slug)
    {
    }

    /**
     * Reads the `slug` route param as a non-empty string.
     *
     * @param PageRouteParams $params Raw subscribe params
     * @throws MissingPageRouteParamException When `slug` is absent or empty
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(
            slug: $params->requireString(self::SLUG),
        );
    }
}
