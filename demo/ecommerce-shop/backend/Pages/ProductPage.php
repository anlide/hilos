<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Browser\ProductPageBasis;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\ShopFront\ProductReading;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageResourceAnswer;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Files\HilosFiles;
use Hilos\HilosException;

/**
 * ProductPage - The storefront's product page, one per address (`/product/{slug}`).
 *
 * Served by the agent of its address: the slug names the {@see ProductAgent} instance, which
 * comes up with the first visitor. A subscription without a slug falls back to the shop's app
 * agent and is refused there. Guests and signed-in visitors get the same product card.
 *
 * @property ProductAgent|EcommerceShopAgent $agent The agent of the address, or the app agent a slug-less subscription falls back to
 */
final class ProductPage extends AbstractPage
{
    public const string PAGE = PageConstants::PRODUCT;

    public const PageReach REACH = PageReach::ROUTE;

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PRODUCT;

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => ProductPageSubscribeParams::SLUG,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => AgentType::ECOMMERCE_SHOP,
    ];

    public const string DATA_PRODUCT = 'product';

    private const string pictureUrl = 'pictureUrl';
    private const string units = 'units';
    private const string availability = 'availability';
    private const string categoryName = 'categoryName';
    private const string categorySlug = 'categorySlug';

    /**
     * Builds the card and the comparison basis from the reading that identifies its resource.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @return PageResourceAnswer The product card and its resource
     * @throws MissingPageRouteParamException When the slug is absent or empty
     * @throws PageResourceNotFoundException When the address names no visible product
     * @throws PageInternalErrorException When the resource key is invalid
     * @throws InvalidArgumentException When the catalog picture variant is misconfigured
     * @throws HilosException When the catalog reading fails
     */
    protected function prepareResourceAnswer(string $acceptKey, PageRouteParams $params): PageResourceAnswer
    {
        $reading = $this->requireReading($params);
        $product = $reading->product;
        $resource = PageResource::db(EcommerceShopDbContext::products, $product->id);
        $basis = new ProductPageBasis($product->categoryId, $reading->fingerprint());

        $payload = new PagePayload(data: [
            self::DATA_PRODUCT => [
                EntityProduct::id => $product->id,
                EntityProduct::slug => $product->slug,
                EntityProduct::name => $product->name,
                EntityProduct::kind => $product->kind->value,
                EntityProduct::price => $product->price,
                EntityProduct::description => $product->description,
                self::pictureUrl => HilosFiles::downloadPath($product->pictureFileId, CatalogPictureUploadTarget::VARIANT_LG),
                self::units => $reading->units,
                self::availability => $reading->availability->value,
                self::categoryName => $reading->category->name,
                self::categorySlug => $reading->category->slug,
            ],
        ]);

        return new PageResourceAnswer($resource, $payload, static function () use ($resource, $basis): void {
            Hilos::$browser?->rememberProductPageBasis($resource, $basis);
        });
    }

    /**
     * Requires the product to be at the exact requested address and visible in the storefront.
     *
     * @param PageRouteParams $params Route params from page subscription
     * @return ProductReading Current public product and its category and stock
     * @throws MissingPageRouteParamException When the slug is absent or empty
     * @throws PageResourceNotFoundException When the address names no visible product
     * @throws HilosException When the catalog reading fails
     */
    private function requireReading(PageRouteParams $params): ProductReading
    {
        $address = ProductPageSubscribeParams::fromPageRouteParams($params);
        $reading = $this->agent instanceof ProductAgent ? $this->agent->reading() : null;
        if ($reading === null || $reading->product->slug !== $address->slug || $reading->availability === ProductAvailability::NOT_PLACED) {
            throw new PageResourceNotFoundException('Product not found.');
        }

        return $reading;
    }
}
