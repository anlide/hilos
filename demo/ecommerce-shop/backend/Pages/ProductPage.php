<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Browser\ProductPageBasis;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\ProductAddToCartActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\ShopFront\ProductReading;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageMovedException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageResourceAnswer;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Files\HilosFiles;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/**
 * ProductPage - The storefront's product page, one per address (`/product/{slug}`).
 *
 * Served by the agent of its address: the slug names the {@see ProductAgent} instance, which
 * comes up with the first visitor. A subscription without a slug falls back to the shop's app
 * agent and is refused there. The card is shared, with a personal count of its other viewers.
 *
 * @property ProductAgent|EcommerceShopAgent $agent The agent of the address, or the app agent a slug-less subscription falls back to
 */
final class ProductPage extends AbstractPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = PageConstants::PRODUCT;

    public const PageReach REACH = PageReach::ROUTE;

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PRODUCT;

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => ProductPageSubscribeParams::SLUG,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => AgentType::ECOMMERCE_SHOP,
    ];

    public const array ACTIONS = [
        EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART => ProductAddToCartActionDTO::class,
    ];

    public const string DATA_PRODUCT = 'product';

    public const string DATA_PRODUCT_VIEWERS = 'productViewers';

    private const string PRODUCT_UNAVAILABLE = 'This product is no longer available on this page.';

    private const string pictureUrl = 'pictureUrl';
    private const string units = 'units';
    private const string availability = 'availability';
    private const string categoryName = 'categoryName';
    private const string categorySlug = 'categorySlug';
    private const string otherViewerCount = 'otherViewerCount';

    /**
     * @param string $acceptKey Connection whose current subscription is being checked
     * @param string $slug Address of the serving product agent
     * @return ?PageResource The product bound by a successful answer for that address
     */
    public static function viewerSubscriptionResource(string $acceptKey, string $slug): ?PageResource
    {
        $subscription = Hilos::$sr?->pageSubscription($acceptKey);
        if ($subscription === null || $subscription->page !== self::PAGE
            || $subscription->agentType !== self::SUBSCRIPTION_AGENT_TYPE || $subscription->agentIndex !== $slug
            || Hilos::$sr->hasPageDeliveryFailure($acceptKey)
            || !$subscription->paramEquals(ProductPageSubscribeParams::SLUG, $slug)
        ) {
            return null;
        }

        $resource = Hilos::$sr->pageResource($acceptKey);

        return $resource?->collectionKey === EcommerceShopDbContext::products ? $resource : null;
    }

    /**
     * Sends the current personal count through the browser's guarded publication path.
     *
     * @param ProductAgent $agent Serving product agent
     * @param string $acceptKey Connection receiving the changed count
     */
    public static function publishViewers(ProductAgent $agent, string $acceptKey): void
    {
        $slug = $agent->address();
        $resource = self::viewerSubscriptionResource($acceptKey, $slug);
        if ($resource === null || $agent->publishedOtherViewers($acceptKey, $resource) === null) {
            return;
        }

        Hilos::$browser?->publishPageData(self::PAGE, $acceptKey, static function () use ($agent, $acceptKey, $slug): PagePayload {
            $current = self::viewerSubscriptionResource($acceptKey, $slug);
            $other = $current === null ? null : $agent->publishedOtherViewers($acceptKey, $current);

            return new PagePayload(data: $other === null ? [] : [
                self::DATA_PRODUCT_VIEWERS => self::viewerData($slug, $other),
            ]);
        }, []);
    }

    /**
     * @param string $acceptKey Connection leaving or refused by the page
     * @throws RtActionsStateCollectionNullException When the viewer counts collection is unavailable
     * @throws HilosException When runtime refuses to publish the remaining count
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        if ($this->agent instanceof ProductAgent) {
            $this->agent->productPageLeft($acceptKey);
        }
    }

    /**
     * Routes the click to the owner of the acting connection's cart.
     *
     * @param string $acceptKey Acting connection
     * @param string $action Browser action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO The cart owner answers separately, so this handler returns null
     * @throws AgentUnknownActionException When the action is not declared by this page
     * @throws InvalidActionPayloadException When the action and payload disagree
     * @throws ValidationException When the card or acting session is no longer suitable
     * @throws InvalidFormatException When the handover address is malformed
     * @throws InvalidArgumentException When the handover cannot be queued
     * @throws HilosException When the catalog reading fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART:
                if (!$dto instanceof ProductAddToCartActionDTO) {
                    throw new InvalidActionPayloadException($action, ProductAddToCartActionDTO::class, $dto);
                }

                $this->forwardCartAdd($acceptKey, $dto);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Builds the card and the comparison basis from the reading that identifies its resource.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @return PageResourceAnswer The product card and its resource
     * @throws MissingPageRouteParamException When the slug is absent or empty
     * @throws PageMovedException When the slug is a former address of a visible product
     * @throws PageResourceNotFoundException When the address names no visible product
     * @throws PageInternalErrorException When the resource or its address declaration is invalid
     * @throws InvalidArgumentException When the catalog picture variant is misconfigured
     * @throws RtActionsStateCollectionNullException When the connections collection is unavailable
     * @throws HilosException When the catalog reading fails
     */
    protected function prepareResourceAnswer(string $acceptKey, PageRouteParams $params): PageResourceAnswer
    {
        $reading = $this->requireReading($params);
        $product = $reading->product;
        $resource = PageResource::db(EcommerceShopDbContext::products, $product->id)
            ->addressedBy(ProductPageSubscribeParams::SLUG, EntityProduct::slug);
        $basis = new ProductPageBasis($product->categoryId, $reading->fingerprint());
        $agent = $this->agent instanceof ProductAgent ? $this->agent : null;
        $audience = $agent?->prepareViewerAnswer($acceptKey, $product->id);

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
            self::DATA_PRODUCT_VIEWERS => self::viewerData($product->slug, $audience?->otherViewers ?? 0),
        ]);

        return new PageResourceAnswer($resource, $payload, static function () use ($resource, $basis, $agent, $acceptKey, $audience): void {
            Hilos::$browser?->rememberProductPageBasis($resource, $basis);
            if ($acceptKey !== '' && $agent !== null && $audience !== null) {
                $agent->productPageAnswered($acceptKey, (int)$resource->idString, $audience);
            }
        });
    }

    /**
     * Hands over the click while the accepted card still names this product at this address.
     *
     * @param string $acceptKey Acting connection
     * @param ProductAddToCartActionDTO $dto Product named by the click
     * @throws ValidationException When the page, product or acting session no longer matches
     * @throws InvalidFormatException When the handover address is malformed
     * @throws InvalidArgumentException When the handover cannot be queued
     * @throws HilosException When the catalog reading fails
     */
    private function forwardCartAdd(string $acceptKey, ProductAddToCartActionDTO $dto): void
    {
        $subscription = Hilos::$sr?->pageSubscription($acceptKey);
        $resource = Hilos::$sr?->pageResource($acceptKey);
        $agent = $this->agent;
        if (!$agent instanceof ProductAgent
            || $subscription?->page !== self::PAGE
            || $subscription->agentType !== AgentType::PRODUCT
            || $subscription->agentIndex !== $agent->address()
            || Hilos::$sr->hasPageDeliveryFailure($acceptKey)
            || $resource?->collectionKey !== EcommerceShopDbContext::products
            || $resource->idString !== (string)$dto->productId) {
            throw new ValidationException(self::PRODUCT_UNAVAILABLE);
        }

        try {
            $reading = $this->requireReading(new PageRouteParams($subscription->stringParams()));
        } catch (MissingPageRouteParamException|PageMovedException|PageResourceNotFoundException) {
            throw new ValidationException(self::PRODUCT_UNAVAILABLE);
        }
        if ($reading->product->id !== $dto->productId) {
            throw new ValidationException(self::PRODUCT_UNAVAILABLE);
        }

        $connection = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey);
        $sessionId = $connection?->sessionId;
        $userId = $connection?->userId;
        if ($sessionId === null || $sessionId <= 0 || ($userId !== null && $userId <= 0)) {
            throw new ValidationException(CartAddProductSignalData::CART_NOT_READY);
        }

        $this->forward(
            $userId === null ? EcommerceShopSignalConstants::GUEST_CART_ADD_PRODUCT : EcommerceShopSignalConstants::ACCOUNT_CART_ADD_PRODUCT,
            new CartAddProductSignalData(
                productId: $dto->productId,
                productSlug: $agent->address(),
                sessionId: $sessionId,
                userId: $userId,
                replySignal: EcommerceShopSignalConstants::CART_PRODUCT_ADD_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART,
                successMessage: null,
            ),
        );
    }

    /**
     * Checks storefront visibility before deciding whether the requested address has moved.
     *
     * @param PageRouteParams $params Route params from page subscription
     * @return ProductReading Current public product and its category and stock
     * @throws MissingPageRouteParamException When the slug is absent or empty
     * @throws PageMovedException When the slug is a former address of a visible product
     * @throws PageResourceNotFoundException When the address names no visible product
     * @throws HilosException When the catalog reading fails
     */
    private function requireReading(PageRouteParams $params): ProductReading
    {
        $address = ProductPageSubscribeParams::fromPageRouteParams($params);
        $reading = $this->agent instanceof ProductAgent ? $this->agent->reading() : null;
        if ($reading === null || $reading->availability === ProductAvailability::NOT_PLACED) {
            throw new PageResourceNotFoundException('Product not found.');
        }
        if ($reading->product->slug !== $address->slug) {
            throw new PageMovedException(static::PAGE, [ProductPageSubscribeParams::SLUG => $reading->product->slug]);
        }

        return $reading;
    }

    /**
     * @param string $slug Current product address
     * @param int $otherViewerCount Number of other viewers
     * @return array<string, int|string> Public viewer data for this card
     */
    private static function viewerData(string $slug, int $otherViewerCount): array
    {
        return [
            EntityProduct::slug => $slug,
            self::otherViewerCount => $otherViewerCount,
        ];
    }
}
