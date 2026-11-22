<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Hilos\Core\Page\AbstractPageSubscribeParamsDTO;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;

/** The storefront category address from the `/category/{slug}` route. */
final class CategoryPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    public const string SLUG = 'slug';

    /**
     * @param string $slug Current category address from the route
     */
    public function __construct(public readonly string $slug)
    {
    }

    /**
     * @param PageRouteParams $params Raw subscription params
     * @return static Parsed category address
     * @throws MissingPageRouteParamException When the slug is absent or empty
     */
    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(slug: $params->requireString(self::SLUG));
    }
}
