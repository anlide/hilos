<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Entity\Item\CategorySlug as EntityCategorySlug;
use Demo\EcommerceShop\Database\View\Item\Category;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CategoryPageSubscribeParams;
use Demo\EcommerceShop\Pages\DTO\ProductAddToCartActionDTO;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\Product\CategoryProductsTable;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
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
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\Exception\TableNotFoundException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * The public storefront category and its first products window at `/category/{slug}`.
 *
 * @property CategoriesLibraryAgent $agent The library that holds the current category addresses
 */
final class CategoryPage extends AbstractPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = PageConstants::CATEGORY;
    public const PageReach REACH = PageReach::ROUTE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CATEGORIES_LIBRARY;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_CATEGORY,
    ];

    public const array ACTIONS = [
        EcommerceShopSignalConstants::CATEGORY_ADD_TO_CART => ProductAddToCartActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            EcommerceShopSignalConstants::CART_CATEGORY_ADD_DONE => CartAddProductDoneSignalData::class,
        ],
    ];

    public const array READS_RT = [EcommerceShopRtContext::connections];

    public const string DATA_CATEGORY = 'category';

    private const string PRODUCT_UNAVAILABLE = 'This product is no longer available in this category.';

    /**
     * Answers updated route params with the category and window under the new address.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated route parameters
     * @throws HilosException When the subscription cannot be answered
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * Routes an add from the open category to the acting connection's cart owner.
     *
     * @param string $acceptKey Acting connection
     * @param string $action Browser action name
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Deferred reply from the writer, so this handler returns null
     * @throws AgentUnknownActionException When the action is not declared by this page
     * @throws InvalidActionPayloadException When the action and payload disagree
     * @throws ValidationException When the category, product or session is no longer suitable
     * @throws InvalidFormatException When the handover address is malformed
     * @throws InvalidArgumentException When a lookup or the handover cannot be prepared
     * @throws CollectionNotFoundException When a catalog collection is not registered
     * @throws DbCollectionNotReadableException When a catalog collection is not readable here
     * @throws LogicException When a catalog collection is not configured
     * @throws DatabaseException When the category or product cannot be read
     * @throws ObjectCollectionNullException When the products collection is detached
     * @throws ObjectGetIdStringNotImplementedException When a matched category cannot provide its key
     * @throws CollectionNotManualException When a category selection cannot accept a matched item
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case EcommerceShopSignalConstants::CATEGORY_ADD_TO_CART:
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
     * Acknowledges the original request once the cart owner reports its outcome.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Full sender identity (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is not declared by this page
     * @throws InvalidAgentSignalPayloadException When the name and payload disagree
     * @throws LogicException When the writer answered a different action on this route
     * @throws InvalidArgumentException When the acknowledgement cannot be queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case EcommerceShopSignalConstants::CART_CATEGORY_ADD_DONE:
                $done = $data->data;
                if (!$done instanceof CartAddProductDoneSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, CartAddProductDoneSignalData::class, $done);
                }
                if ($done->answer->action !== EcommerceShopSignalConstants::CATEGORY_ADD_TO_CART) {
                    throw new LogicException('The cart owner answered a different action on the category route.');
                }
                $this->answerHandover($done->answer);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @return PageResourceAnswer Current category card and resource for the reply with its products window
     * @throws MissingPageRouteParamException When the slug is absent or empty
     * @throws PageMovedException When the slug is a former address of an existing category
     * @throws PageResourceNotFoundException When the slug names no category
     * @throws PageInternalErrorException When the resource or its address declaration is invalid
     * @throws CollectionNotFoundException When a catalog collection is not registered
     * @throws DbCollectionNotReadableException When a catalog collection is not readable here
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a lookup names an invalid column or the object type mismatches
     * @throws DatabaseException When the category or its address cannot be read
     * @throws ItemNotFoundException When a non-retired address has no category at its key
     * @throws ObjectGetIdStringNotImplementedException When a matched object cannot provide its key
     * @throws CollectionNotManualException When the selection cannot accept a matched item
     * @throws TableNotFoundException When the category products table is not registered
     */
    protected function prepareResourceAnswer(string $acceptKey, PageRouteParams $params): PageResourceAnswer
    {
        $address = CategoryPageSubscribeParams::fromPageRouteParams($params);
        $category = $this->categoryAtCurrentAddress($address);
        if ($category === null) {
            $formerAddress = Hilos::$db->categorySlugs->whereColumnIs(EntityCategorySlug::slug, $address->slug)->first();
            if ($formerAddress === null || $formerAddress->slug !== $address->slug || $formerAddress->categoryId === null) {
                throw new PageResourceNotFoundException('Category not found.');
            }

            throw new PageMovedException(static::PAGE, [
                CategoryPageSubscribeParams::SLUG => Hilos::$db->categories[$formerAddress->categoryId]->slug,
            ]);
        }

        $resource = PageResource::db(EcommerceShopDbContext::categories, $category->id)
            ->addressedBy(CategoryPageSubscribeParams::SLUG, EntityCategory::slug);
        $this->scopeProductsWindow($acceptKey, $category->id);

        $payload = new PagePayload(data: [
            self::DATA_CATEGORY => [
                EntityCategory::id => $category->id,
                EntityCategory::slug => $category->slug,
                EntityCategory::name => $category->name,
                EntityCategory::description => $category->description,
            ],
        ]);

        return new PageResourceAnswer($resource, $payload);
    }

    /**
     * Resolves an exact current address; the database comparison may also match a trailing space.
     *
     * @param CategoryPageSubscribeParams $address Category address from the route
     * @return ?Category Category at its current address, or null
     * @throws CollectionNotFoundException When the category collection is not registered
     * @throws DbCollectionNotReadableException When the category collection is not readable here
     * @throws LogicException When the category collection is not configured
     * @throws InvalidArgumentException When the lookup names an invalid column or the object type mismatches
     * @throws DatabaseException When the category cannot be read
     * @throws ObjectGetIdStringNotImplementedException When a matched object cannot provide its key
     * @throws CollectionNotManualException When the selection cannot accept a matched item
     */
    private function categoryAtCurrentAddress(CategoryPageSubscribeParams $address): ?Category
    {
        $category = Hilos::$db->categories->whereColumnIs(EntityCategory::slug, $address->slug)->first();

        return $category?->slug === $address->slug ? $category : null;
    }

    /**
     * Hands over the add after checking the category the connection was shown and the product's current membership.
     *
     * @param string $acceptKey Acting connection
     * @param ProductAddToCartActionDTO $dto Product named by the click
     * @throws ValidationException When the category, product or session is no longer suitable
     * @throws InvalidFormatException When the handover address is malformed
     * @throws InvalidArgumentException When a lookup or the handover cannot be prepared
     * @throws CollectionNotFoundException When a catalog collection is not registered
     * @throws DbCollectionNotReadableException When a catalog collection is not readable here
     * @throws LogicException When a catalog collection is not configured
     * @throws DatabaseException When the category or product cannot be read
     * @throws ObjectCollectionNullException When the products collection is detached
     * @throws ObjectGetIdStringNotImplementedException When a matched category cannot provide its key
     * @throws CollectionNotManualException When a category selection cannot accept a matched item
     */
    private function forwardCartAdd(string $acceptKey, ProductAddToCartActionDTO $dto): void
    {
        $subscription = Hilos::$sr?->pageSubscription($acceptKey);
        $resource = Hilos::$sr?->pageResource($acceptKey);
        if ($subscription?->page !== self::PAGE
            || $resource?->collectionKey !== EcommerceShopDbContext::categories
            || Hilos::$sr->hasPageDeliveryFailure($acceptKey)) {
            throw new ValidationException(self::PRODUCT_UNAVAILABLE);
        }

        try {
            $address = CategoryPageSubscribeParams::fromPageRouteParams(new PageRouteParams($subscription->stringParams()));
        } catch (MissingPageRouteParamException) {
            throw new ValidationException(self::PRODUCT_UNAVAILABLE);
        }
        $category = $this->categoryAtCurrentAddress($address);
        if ($category === null || $resource->idString !== (string) $category->id) {
            throw new ValidationException(self::PRODUCT_UNAVAILABLE);
        }
        $productSlug = Hilos::$db->products->storedSlugInCategory($dto->productId, $category->id);
        if ($productSlug === null) {
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
                productSlug: $productSlug,
                sessionId: $sessionId,
                userId: $userId,
                replySignal: EcommerceShopSignalConstants::CART_CATEGORY_ADD_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: EcommerceShopSignalConstants::CATEGORY_ADD_TO_CART,
                successMessage: null,
            ),
        );
    }

    /**
     * Lets the snapshot use a window only when it belongs to the category in the reply.
     *
     * @param string $acceptKey Subscribing connection
     * @param int $categoryId Category id resolved from the route
     * @throws TableNotFoundException When the category products table is not registered
     */
    private function scopeProductsWindow(string $acceptKey, int $categoryId): void
    {
        $router = Hilos::$sr;
        if ($router === null) {
            return;
        }

        $tableKey = CategoryProductsTable::TABLE;
        $reported = $router->takeReportedTableWindows($acceptKey)[$tableKey] ?? null;
        if (($reported?->filter[CategoryProductsTable::FILTER_CATEGORY] ?? null) === $categoryId) {
            $router->reportTableWindows($acceptKey, [$tableKey => $reported]);
            return;
        }

        $held = $router->getTableViewport($acceptKey, $tableKey);
        if (($held?->filter[CategoryProductsTable::FILTER_CATEGORY] ?? null) === $categoryId) {
            return;
        }

        $table = Hilos::$table->categoryProducts;
        $router->setTableViewport($acceptKey, new TableViewportSubscription(
            tableKey: $tableKey,
            filter: [CategoryProductsTable::FILTER_CATEGORY => $categoryId],
            sort: $table->defaultSort(),
            limit: $table->windowSize(),
        ));
        $router->setTableFacets($acceptKey, $tableKey, []);
    }
}
