<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser;

use Closure;
use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Browser\List\MainCategoriesBrowserList;
use Demo\EcommerceShop\Browser\List\MainProductsBrowserList;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart as EntitySessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart as EntitySessionVoucherCart;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart as EntityUserProductCart;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Demo\EcommerceShop\Database\Object\Item\SessionVoucherCart as ObjectSessionVoucherCart;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminProductPage;
use Demo\EcommerceShop\Pages\CartPage;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\ShopFront\ProductCartCount;
use Demo\EcommerceShop\ShopFront\ProductPageAddresses;
use Demo\EcommerceShop\ShopFront\ProductReading;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\PageSubscriptionContext;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\PageSubscription;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Files\HilosFiles;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Utils\Logger;
use Hilos\WiringRefusal;
use JsonException;
use Throwable;
use WeakMap;

/**
 * EcommerceShopBrowserContext - Browser-facing context ($browser layer) for ecommerce-shop.
 *
 * Says who is behind a socket - the admin pages ask it through the page access gate - and
 * computes the two fields of the home page: the address of a category's tile picture and whether
 * a product is on the shop front; and the fields of the product card on the admin_product page:
 * the picture's address, the edit version, the stock total and the storefront viewer count. A person's presence and
 * session count, the password fields and the scheduled deletion's date are the framework's,
 * computed for its card of one person (HIL-1254), and the other admin tables go out through the
 * framework self-snapshot path.
 */
final class EcommerceShopBrowserContext extends BrowserContext
{
    /** @var array<int, TableMutationType> Product facts by product key until this flush */
    private array $changedProducts = [];

    /** @var array<int, ?int> Changed stock key to product key, null for sparse updates needing a lookup */
    private array $changedStock = [];

    /** @var array<int, true> Changed category keys until this flush */
    private array $changedCategories = [];

    /** @var array<int, true> Products whose stored cart membership changed this flush */
    private array $changedProductCarts = [];

    /** @var bool Whether a cart collection clear invalidates every current product card */
    private bool $productCartsCleared = false;

    /** @var ?array<int, int|HilosException> Cart reads shared by full and partial answers during this flush */
    private ?array $productCartCounts = null;

    /** @var ?array<int|string, ProductReading|HilosException|null> Address readings shared only inside one synchronous flush */
    private ?array $productReadings = null;

    /** @var ?ProductPageAddresses PRIMARY reads and their connection boundary for the current flush */
    private ?ProductPageAddresses $productPageAddresses = null;

    /** @var array<int, true> Product ids confirmed by a completed address read in this flush */
    private array $confirmedProductIds = [];

    /** @var WeakMap<PageResource, ProductPageBasis> Comparison values owned by the resource of a successful answer */
    private WeakMap $productPageBases;

    /** @var WeakMap<PageSubscription, true> Live candidates whose exact membership needs another read */
    private WeakMap $unresolvedProductPages;

    /**
     * Records an applied browser source fact and indexes the changes that may affect a product card.
     *
     * @param SourceChange $change Source fact to dispatch on the next browser flush
     */
    public function record(SourceChange $change): void
    {
        parent::record($change);
        if ($change->kind !== SourceChange::KIND_DB) {
            return;
        }

        $id = (int) $change->sourceId;
        $mutation = $change->mutationType;
        if ($change->sourceKey === EcommerceShopDbContext::products) {
            if ($mutation === TableMutationType::Update
                || ($mutation === TableMutationType::Create && !isset($this->changedProducts[$id]))
            ) {
                $this->changedProducts[$id] = $mutation;
            }
        } elseif ($change->sourceKey === EcommerceShopDbContext::categories && $mutation === TableMutationType::Update) {
            $this->changedCategories[$id] = true;
        } elseif ($change->sourceKey === EcommerceShopDbContext::productWarehouseStocks) {
            $productId = $this->productIdOfStockFact($change);
            if ($productId !== null || ($mutation === TableMutationType::Update && !isset($this->changedStock[$id]))) {
                $this->changedStock[$id] = $productId;
            }
        } elseif ($change->sourceKey === EcommerceShopDbContext::sessionProductCarts
            || $change->sourceKey === EcommerceShopDbContext::userProductCarts
        ) {
            $this->recordProductCartChange($change);
        }
    }

    /**
     * Shares cart reads across resource transitions, catalog fan-out and debt recovery.
     *
     * @return list<ContainedFailure> Failures from addressed browser delivery
     * @throws InvalidArgumentException When a fanned-out signal cannot be named
     */
    public function flushToSignalRouter(): array
    {
        $this->productCartCounts = [];
        $this->productReadings = [];
        $this->productPageAddresses = new ProductPageAddresses();
        try {
            return parent::flushToSignalRouter();
        } finally {
            $this->productCartCounts = null;
            $this->productReadings = null;
            $this->productPageAddresses = null;
            $this->confirmedProductIds = [];
        }
    }

    /**
     * @param string $slug Requested storefront address
     * @return ?ProductReading Snapshot shared by pages answering the same address during this flush
     * @throws HilosException When the catalog snapshot cannot be read
     */
    public function productReading(string $slug): ?ProductReading
    {
        if ($this->productReadings === null) {
            return ProductReading::atAddress($slug);
        }
        if (!array_key_exists($slug, $this->productReadings)) {
            try {
                $reading = $this->productPageAddresses->readPrimary(static fn (): ?ProductReading => ProductReading::atAddress($slug));
                $this->productReadings[$slug] = $reading;
                if ($reading !== null) {
                    $this->confirmedProductIds[$reading->product->id] = true;
                }
            } catch (HilosException $failure) {
                $this->productReadings[$slug] = $failure;
            }
        }
        $reading = $this->productReadings[$slug];
        if ($reading instanceof HilosException) {
            throw $reading;
        }

        return $reading;
    }

    /**
     * Reads the cart aggregate under the caller's page error boundary.
     *
     * @param int $productId Product named by the current answer
     * @return int Number of stored carts containing the product
     * @throws HilosException When the collections or transaction refuse the read
     * @throws LogicException When the read fails unexpectedly
     */
    public function productCartCount(int $productId): int
    {
        if ($this->productCartCounts === null) {
            return ProductCartCount::forProduct($productId);
        }
        if (!isset($this->productCartCounts[$productId])) {
            try {
                $this->productCartCounts[$productId] = $this->productPageAddresses->readPrimary(
                    static fn (): int => ProductCartCount::forProduct($productId),
                );
            } catch (HilosException $failure) {
                $this->productCartCounts[$productId] = $failure;
            }
        }
        $count = $this->productCartCounts[$productId];
        if ($count instanceof HilosException) {
            throw $count;
        }

        return $count;
    }

    /**
     * @param int $productId Numeric Overview address
     * @return bool Whether the address names a product
     * @throws HilosException When the product lookup or a previous PRIMARY read fails
     */
    public function overviewProductExists(int $productId): bool
    {
        if ($this->productPageAddresses?->primaryUnavailable() && isset($this->confirmedProductIds[$productId])) {
            return true;
        }

        $exists = static fn (): bool => isset(Hilos::$db->products[$productId]);
        if ($this->productPageAddresses === null) {
            return $exists();
        }

        try {
            return $this->productPageAddresses->observePrimary($exists);
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('The Overview address could not be read', previous: $failure);
        }
    }

    /**
     * Overview's whole snapshot always queries its category and warehouse lists.
     *
     * @throws DatabaseException When the current flush has already lost PRIMARY
     */
    public function assertOverviewSnapshotAvailable(): void
    {
        $this->productPageAddresses?->assertPrimaryAvailable();
    }

    /**
     * @param string $page Page being delivered
     * @return ?Closure(Closure(): mixed): mixed PRIMARY scope for Overview delivery during this flush
     */
    protected function pageDeliveryScope(string $page): ?Closure
    {
        return $page === AdminProductPage::PAGE && $this->productPageAddresses !== null
            ? $this->productPageAddresses->observePrimary(...)
            : null;
    }

    /**
     * Keeps the comparison basis for the resource bound by the full answer.
     *
     * @param PageResource $resource Resource of the answered product page
     * @param ProductPageBasis $basis Comparison values from the delivered card
     */
    public function rememberProductPageBasis(PageResource $resource, ProductPageBasis $basis): void
    {
        $this->productPageBases ??= new WeakMap();
        $this->productPageBases[$resource] = $basis;
    }

    /**
     * Computes the home page's browser fields and the admin product card's, and hands every other
     * name to the framework.
     *
     * The item is built from a live row of its anchor, so the category or the product is read by
     * its key without a check that it is there.
     *
     * @param string $browserKey Browser list or table key
     * @param string $field Computed field name from the mirrored browser config
     * @param int|string $rowKey Logical browser row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when the field is unknown
     * @throws PageInternalErrorException When a computed field cannot be resolved
     * @throws RtActionsStateCollectionNullException When a project's runtime field source is unavailable
     * @throws RtCollectionNotReadableException When the runtime collection is unreadable
     */
    protected function computeBrowserField(
        string $browserKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): mixed {
        if ($field === MainCategoriesBrowserList::TILE_PICTURE) {
            try {
                return HilosFiles::downloadPath(
                    Hilos::$db->categories[(int) $rowKey]->pictureFileId,
                    CatalogPictureUploadTarget::VARIANT_TILE,
                );
            } catch (DatabaseException|InvalidArgumentException|LogicException $exception) {
                throw new PageInternalErrorException('Category tile picture could not be resolved', $exception);
            }
        }

        if ($field === MainProductsBrowserList::ON_SHOP_FRONT) {
            try {
                $product = Hilos::$db->products[(int) $rowKey];
                $units = Hilos::$db->productWarehouseStocks->totalsOfProducts($product->id)->of($product->id)->unitCount;

                return ProductAvailability::of($product->kind, $units) !== ProductAvailability::NOT_PLACED;
            } catch (
                DatabaseException
                | InvalidArgumentException
                | LogicException
                | ObjectGetIdStringNotImplementedException
                | CollectionNotManualException $exception
            ) {
                throw new PageInternalErrorException('Product shop front state could not be resolved', $exception);
            }
        }

        if ($browserKey === AdminProductBrowserData::DATA) {
            try {
                $product = Hilos::$db->products[(int) $rowKey];
                if ($field === AdminProductBrowserData::FIELD_PICTURE_URL) {
                    return HilosFiles::downloadPath($product->pictureFileId, CatalogPictureUploadTarget::VARIANT_LG);
                }

                if ($field === AdminProductBrowserData::FIELD_EDIT_VERSION) {
                    return $product->editVersion();
                }

                if ($field === AdminProductBrowserData::FIELD_STOCK) {
                    $totals = Hilos::$db->productWarehouseStocks->totalsOfProducts($product->id)->of($product->id);

                    return [
                        AdminProductBrowserData::STOCK_UNITS => $totals->unitCount,
                        AdminProductBrowserData::STOCK_WAREHOUSES => $totals->warehouseCount,
                        AdminProductBrowserData::STOCK_WAREHOUSE_ID => $product->kind === ProductKind::UNIQUE ? $totals->warehouseId : null,
                    ];
                }

                if ($field === AdminProductBrowserData::FIELD_VIEWER_COUNT) {
                    $viewers = Hilos::$rt->productViewerCounts[$product->slug];

                    return $viewers !== null && $viewers->productId === $product->id ? $viewers->viewerCount : 0;
                }
            } catch (
                DatabaseException
                | InvalidArgumentException
                | LogicException
                | ObjectGetIdStringNotImplementedException
                | CollectionNotManualException
                | JsonException $exception
            ) {
                throw new PageInternalErrorException('Admin product card could not be resolved', $exception);
            }
        }

        return parent::computeBrowserField(
            $browserKey,
            $field,
            $rowKey,
            $acceptKey,
            $pageParams,
            $browserParams,
            $sources,
        );
    }

    /**
     * Resolves who is behind an accept key from the demo's runtime connection
     * registry, where the handshake records the acceptKey -> user mapping. Lets the
     * page access gate identify the subscriber.
     *
     * A registry row is written for every connection the handshake sees, so no row
     * means the row has not crossed the RT sync into this worker yet rather than
     * "nobody is there" - the frame waits instead of being refused as anonymous
     * (HIL-599). A demo that mounts no registry at all is the opposite case and answers a
     * settled nobody: where the answer can never arrive there is nothing to wait for.
     *
     * Fail-closed stops there (HIL-575). It belongs where the question is who this
     * connection is; it does not belong to a read that was refused, which says nothing
     * about the connection and everything about the wiring. Answered "anonymous", a
     * refusal signed everybody out of a node that was merely wired wrong.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ConnectionIdentity User behind the connection, or the pending state
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        try {
            $connections = Hilos::$rt?->connections;
            if ($connections === null) {
                return ConnectionIdentity::resolved(null);
            }

            $connection = $connections[$acceptKey];

            return $connection === null
                ? ConnectionIdentity::pending()
                : ConnectionIdentity::resolved($connection->userId);
        } catch (RtCollectionNotFoundException) {
            return ConnectionIdentity::resolved(null);
        }
    }

    /**
     * Matches a cart line or voucher binding of the current owner, or a catalog change affecting that owner's cart.
     * A binding is keyed by its owner, so its fact names the owner; a voucher fact names the voucher the owner selected.
     *
     * @param string $page Page name from the subscription mirror
     * @param string $acceptKey Connection whose session and serving agent must agree
     * @param SourceChange $change Grouped source fact
     * @return bool Whether this cart needs a new snapshot
     * @throws RtActionsStateCollectionNullException When runtime connections are unavailable
     * @throws HilosException When a configured source refuses a read
     * @throws LogicException When an ORM collection is misconfigured
     * @throws InvalidArgumentException When an ORM query or item is invalid
     * @throws DatabaseException When a cart, stock or voucher binding row cannot be read
     */
    protected function observesOwnPageChange(string $page, string $acceptKey, SourceChange $change): bool
    {
        if ($page !== PageConstants::CART || !$change->isDb() || !in_array($change->sourceKey, [
            EcommerceShopDbContext::sessionProductCarts,
            EcommerceShopDbContext::userProductCarts,
            EcommerceShopDbContext::products,
            EcommerceShopDbContext::productWarehouseStocks,
            EcommerceShopDbContext::sessionVoucherCarts,
            EcommerceShopDbContext::userVoucherCarts,
            EcommerceShopDbContext::vouchers,
        ], true)) {
            return false;
        }

        $subscription = Hilos::$sr?->pageSubscription($acceptKey);
        $connection = Hilos::$rt->connections[$acceptKey];
        if ($subscription?->page !== $page || $connection === null || $connection->staleSince() !== null) {
            return false;
        }

        $userId = $connection->userId;
        $sessionId = $connection->sessionId;
        if (!CartPage::cartOwnerMatches($userId, $sessionId, $subscription->agentType, $subscription->agentIndex)) {
            return false;
        }

        $cartEpoch = null;
        if ($userId === null) {
            $token = $connection->sessionToken;
            $cartEpoch = $token === null ? null : Hilos::$db->sessions->activeGuestCartEpoch($sessionId, $token);
            if ($cartEpoch === null) {
                return false;
            }
        }

        if ($change->sourceKey === EcommerceShopDbContext::sessionProductCarts
            || $change->sourceKey === EcommerceShopDbContext::sessionVoucherCarts
        ) {
            return $cartEpoch !== null && $this->guestCartChangeMatches($change, $sessionId, $cartEpoch);
        }
        if ($change->sourceKey === EcommerceShopDbContext::userProductCarts) {
            return $userId !== null && $this->cartLineChangeMatchesOwner($change, $userId);
        }
        if ($change->sourceKey === EcommerceShopDbContext::userVoucherCarts) {
            return $userId !== null && ($change->mutationType === TableMutationType::Clear || (int) $change->sourceId === $userId);
        }
        if ($change->sourceKey === EcommerceShopDbContext::vouchers) {
            $voucherId = $cartEpoch !== null
                ? Hilos::$db->sessionVoucherCarts->storedVoucherId($sessionId, $cartEpoch)
                : Hilos::$db->userVoucherCarts->storedVoucherId($userId);

            return $voucherId !== null && ($change->mutationType === TableMutationType::Clear || $voucherId === (int)$change->sourceId);
        }

        $hasCartLine = $cartEpoch !== null
            ? static fn (?int $productId = null): bool => Hilos::$db->sessionProductCarts->hasCartLine($sessionId, $cartEpoch, $productId)
            : static fn (?int $productId = null): bool => Hilos::$db->userProductCarts->hasCartLine($userId, $productId);
        if ($change->mutationType === TableMutationType::Clear) {
            return $hasCartLine();
        }
        if ($change->sourceKey === EcommerceShopDbContext::products) {
            return $hasCartLine((int) $change->sourceId);
        }
        if (!$hasCartLine()) {
            return false;
        }
        foreach ($this->cartProductIdsOfStockChange($change) as $productId) {
            if ($hasCartLine($productId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gives full catalog answers priority over cart-count updates in the same flush.
     *
     * @param bool $databaseBack Whether this worker recovered its database connection
     */
    protected function publishLocalPageFacts(bool $databaseBack): void
    {
        $changedProductCarts = $this->changedProductCarts;
        $productCartsCleared = $this->productCartsCleared;
        $this->changedProductCarts = [];
        $this->productCartsCleared = false;

        $this->publishProductCardFacts($databaseBack);
        if ($changedProductCarts !== [] || $productCartsCleared) {
            $this->publishProductCartCounts($changedProductCarts, $productCartsCleared);
        }
    }

    /**
     * @param string $page Page whose subscription owes a whole answer
     * @return bool Whether recovery can confirm a former storefront address
     */
    protected function reportsMoveOnPageRecovery(string $page): bool
    {
        return $page === ProductPage::PAGE;
    }

    /**
     * Publishes one complete storefront card per affected subscription from the final state of the worker flush.
     *
     * @param bool $databaseBack Whether this worker recovered its database connection
     */
    private function publishProductCardFacts(bool $databaseBack): void
    {
        $products = $this->changedProducts;
        $stock = $this->changedStock;
        $categories = $this->changedCategories;
        $this->changedProducts = [];
        $this->changedStock = [];
        $this->changedCategories = [];

        $hasFacts = $products !== [] || $stock !== [] || $categories !== [];
        $router = Hilos::$sr;
        if ($router === null || (!$hasFacts && !$databaseBack)) {
            return;
        }

        $bound = [];
        $unbound = [];
        foreach ($router->pageSubscriptionRecords() as $acceptKey => $subscription) {
            if ($subscription->page !== PageConstants::PRODUCT || $this->isPageFanOutDeferred((string) $acceptKey)) {
                continue;
            }
            $slug = $subscription->stringParams()[ProductPageSubscribeParams::SLUG] ?? null;
            if ($slug === null || $slug === '') {
                continue;
            }
            $context = new PageSubscriptionContext($router, (string) $acceptKey, $subscription);
            $resource = $router->pageResource($acceptKey);
            if ($resource?->collectionKey === EcommerceShopDbContext::products) {
                $bound[(int) $resource->idString][$acceptKey] = $context;
            } elseif ($resource === null && $router->hasPageDeliveryFailure($acceptKey)) {
                $unbound[$slug][$acceptKey] = $context;
            }
        }
        if ($bound === [] && $unbound === []) {
            return;
        }
        $this->unresolvedProductPages ??= new WeakMap();
        $retry = $this->unresolvedProductPages;
        $this->unresolvedProductPages = new WeakMap();
        $unresolvedStock = false;
        foreach ($stock as $stockId => $productId) {
            if ($productId === null && $this->productPageAddresses->primaryUnavailable()) {
                $unresolvedStock = true;
                continue;
            }
            try {
                $productId ??= $this->productPageAddresses->readPrimary(
                    static fn (): ?int => Hilos::$db->productWarehouseStocks->productIdAtStockKey($stockId),
                );
                if ($productId !== null) {
                    $products[$productId] = TableMutationType::Update;
                }
            } catch (WiringRefusal) {
                // The database context already reported the missing read interest or readiness.
            } catch (HilosException $failure) {
                $unresolvedStock = $unresolvedStock || $failure instanceof DatabaseException;
                Logger::error("Product page stock lookup failed for {$stockId}: {$failure->getMessage()}");
            }
        }

        $pending = [];
        foreach ($bound as $productId => $subscribers) {
            $changed = ($products[$productId] ?? null) === TableMutationType::Update;
            foreach ($subscribers as $acceptKey => $context) {
                if (!$context->isCurrent()) {
                    continue;
                }
                $subscription = $context->subscription;
                if ($changed || $categories !== [] || isset($retry[$subscription])) {
                    $pending[$productId][$acceptKey] = $context;
                }
                if ($unresolvedStock) {
                    $this->unresolvedProductPages[$subscription] = true;
                }
            }
        }

        $reconcile = [];
        foreach ($this->productPageAddresses->readBatches(
            array_keys($pending),
            static fn (array $ids): array => iterator_to_array(Hilos::$db->products->storedProducts(EntityProduct::id, ...$ids)),
            function (array $ids, Throwable $failure) use ($pending, $products, $categories): void {
                if ($failure instanceof WiringRefusal) {
                    return;
                }
                foreach ($ids as $id) {
                    $this->failBoundProductLookup($pending[$id], ($products[$id] ?? null) === TableMutationType::Update, $categories, $failure);
                }
            },
        ) as $batch) {
            foreach ($batch as $productId => $product) {
                $changed = ($products[$productId] ?? null) === TableMutationType::Update;
                foreach ($pending[$productId] as $acceptKey => $context) {
                    if (!$context->isCurrent()) {
                        continue;
                    }
                    if ($changed || isset($categories[$product->categoryId])) {
                        $this->resendAddressedPageWhole($context, reportMove: true);
                    } elseif (isset($retry[$context->subscription])) {
                        $reconcile[$productId][$acceptKey] = [$context, $product];
                    }
                }
            }
        }

        foreach ($this->publishUnboundProductCards($unbound, $products, $categories, $retry, $unresolvedStock) as $productId => $subscribers) {
            $reconcile[$productId] = ($reconcile[$productId] ?? []) + $subscribers;
        }
        $this->reconcileProductPages($reconcile);
    }

    /**
     * @param array<string, PageSubscriptionContext> $subscribers Subscribers of the unread product
     * @param bool $changed Whether a fact named the product directly
     * @param array<int, true> $categories Categories changed this flush
     * @param Throwable $failure Why the product could not be read
     */
    private function failBoundProductLookup(array $subscribers, bool $changed, array $categories, Throwable $failure): void
    {
        foreach ($subscribers as $acceptKey => $context) {
            if (!$context->isCurrent()) {
                continue;
            }
            $basis = $this->productPageBasis($context->router->pageResource($acceptKey));
            if ($changed || ($basis !== null && isset($categories[$basis->categoryId]))) {
                $this->failAddressedPublication($context, $failure);
            } elseif ($failure instanceof DatabaseException) {
                $this->unresolvedProductPages[$context->subscription] = true;
            }
        }
    }

    /**
     * @param array<int|string, array<string, PageSubscriptionContext>> $unbound Exact routes still owed a card
     * @param array<int, TableMutationType> $products Products changed by catalog or warehouse facts
     * @param array<int, true> $categories Changed categories
     * @param WeakMap<PageSubscription, true> $retry Last flush's uncertain subscriptions
     * @param bool $unresolvedStock Whether a new warehouse fact could not be assigned to a product
     * @return array<int, array<string, array{PageSubscriptionContext, Product}>> Subscriptions needing stock reconciliation
     */
    private function publishUnboundProductCards(array $unbound, array $products, array $categories, WeakMap $retry, bool $unresolvedStock): array
    {
        $slugs = [];
        $needsReconciliation = false;
        foreach ($unbound as $slug => $subscribers) {
            foreach ($subscribers as $context) {
                if (!$context->isCurrent()) {
                    continue;
                }
                $subscription = $context->subscription;
                if ($unresolvedStock) {
                    $this->unresolvedProductPages[$subscription] = true;
                }
                $needsReconciliation = $needsReconciliation || isset($retry[$subscription]);
                if ($products !== [] || $categories !== [] || isset($retry[$subscription])) {
                    $slugs[$slug] = (string) $slug;
                }
            }
        }

        $targeted = $categories === [] && !$needsReconciliation;
        $matched = $this->productPageAddresses->matchingProducts(
            $slugs,
            $targeted ? array_keys($products) : null,
            $targeted ? null : function (array $addresses) use ($unbound): void {
                foreach ($addresses as $slug) {
                    $this->rememberUnresolvedProductPages($unbound[$slug]);
                }
            },
        );
        $pending = [];
        foreach ($matched as $slug => $productId) {
            foreach ($unbound[$slug] as $acceptKey => $context) {
                if (isset($products[$productId])) {
                    $this->resendAddressedPageWhole($context, reportMove: true);
                } elseif ($categories !== [] || isset($retry[$context->subscription])) {
                    $pending[$productId][$acceptKey] = $context;
                }
            }
        }

        $reconcile = [];
        foreach ($this->productPageAddresses->readBatches(
            array_keys($pending),
            static fn (array $ids): array => iterator_to_array(Hilos::$db->products->storedProducts(EntityProduct::id, ...$ids)),
            function (array $ids) use ($pending): void {
                foreach ($ids as $id) {
                    $this->rememberUnresolvedProductPages($pending[$id]);
                }
            },
        ) as $batch) {
            foreach ($batch as $productId => $product) {
                foreach ($pending[$productId] as $acceptKey => $context) {
                    if (isset($categories[$product->categoryId])) {
                        $this->resendAddressedPageWhole($context, reportMove: true);
                    } elseif (isset($retry[$context->subscription])) {
                        $reconcile[$productId][$acceptKey] = [$context, $product];
                    }
                }
            }
        }

        return $reconcile;
    }

    /**
     * Collects the cart facts that can change how many saved lines contain the product.
     *
     * @param SourceChange $change Guest or account cart line fact
     */
    private function recordProductCartChange(SourceChange $change): void
    {
        if ($change->mutationType === TableMutationType::Clear) {
            $this->productCartsCleared = true;
            return;
        }
        if ($change->mutationType !== TableMutationType::Create && $change->mutationType !== TableMutationType::Delete) {
            return;
        }

        $guest = $change->sourceKey === EcommerceShopDbContext::sessionProductCarts;
        $objectKey = $guest ? ObjectSessionProductCart::productId : ObjectUserProductCart::productId;
        $entityKey = $guest ? EntitySessionProductCart::product_id : EntityUserProductCart::product_id;
        foreach ([$change->carriedRow(), $change->carriedPrevious()] as $row) {
            $productId = $row[$objectKey] ?? $row[$entityKey] ?? null;
            if ((is_int($productId) || is_string($productId)) && (int) $productId > 0) {
                $this->changedProductCarts[(int) $productId] = true;
            }
        }
    }

    /**
     * Reads each affected count once, after the guarded publisher admits a current card for it.
     *
     * @param array<int, true> $changed Products named by cart changes
     * @param bool $cleared Whether a cart collection was cleared
     */
    private function publishProductCartCounts(array $changed, bool $cleared): void
    {
        $router = Hilos::$sr;
        if ($router === null) {
            return;
        }

        $unbound = [];
        foreach ($router->pageSubscriptionRecords() as $acceptKey => $subscription) {
            $acceptKey = (string) $acceptKey;
            $page = $subscription->page;
            if (($page !== ProductPage::PAGE && $page !== AdminProductPage::PAGE) || $this->isPageFanOutDeferred($acceptKey)) {
                continue;
            }
            $context = new PageSubscriptionContext($router, $acceptKey, $subscription);
            $resource = $router->pageResource($acceptKey);
            if ($resource === null && $router->hasPageDeliveryFailure($acceptKey)) {
                $unbound[] = $context;
                continue;
            }
            if ($resource?->collectionKey !== EcommerceShopDbContext::products) {
                continue;
            }
            $productId = (int) $resource->idString;
            if (!$cleared && !isset($changed[$productId])) {
                continue;
            }

            $storefront = $page === ProductPage::PAGE;
            if ($storefront && $router->hasPageDeliveryFailure($acceptKey)) {
                $this->retryStorefrontCartDebt($context, $productId);
                continue;
            }

            $this->publishPageData($page, $acceptKey, function () use ($context, $resource, $productId, $storefront): PagePayload {
                if ($context->router->pageResource($context->acceptKey) !== $resource) {
                    return new PagePayload();
                }
                $slug = null;
                if ($storefront) {
                    $slug = $context->subscription->stringParams()[ProductPageSubscribeParams::SLUG] ?? null;
                    $reading = $slug === null ? null : $this->productReading($slug);
                    if ($reading === null || $reading->product->id !== $productId
                        || $reading->availability === ProductAvailability::NOT_PLACED
                    ) {
                        return new PagePayload();
                    }
                } elseif (!$this->overviewProductExists($productId)) {
                    return new PagePayload();
                }

                if (!$context->isCurrent() || $context->router->pageResource($context->acceptKey) !== $resource) {
                    return new PagePayload();
                }
                if ($storefront && $reading->product->slug !== $slug) {
                    $this->resendAddressedPageWhole($context, reportMove: true);
                    return new PagePayload();
                }

                return new PagePayload(data: [
                    ($storefront ? ProductPage::DATA_PRODUCT_CARTS : AdminProductPage::DATA_PRODUCT_CARTS)
                        => ProductCartCount::data($productId, $this->productCartCount($productId), $slug),
                ]);
            }, $storefront ? [] : AdminProductPage::productCartsFields());
        }

        $this->retryUnboundProductCartPages($unbound, $changed, $cleared);
    }

    /**
     * Tries the whole answer for unbound routes addressed by the cart facts.
     *
     * @param list<PageSubscriptionContext> Subscriptions owed an answer without a bound resource
     * @param array<int, true> $changed Products named by cart changes
     * @param bool $cleared Whether a cart collection was cleared
     */
    private function retryUnboundProductCartPages(array $contexts, array $changed, bool $cleared): void
    {
        $slugs = [];
        $routes = [];
        foreach ($contexts as $index => $context) {
            $subscription = $context->subscription;
            if ($subscription === null || !$context->isCurrent()) {
                continue;
            }

            $params = new PageRouteParams($subscription->stringParams());
            if ($subscription->page === ProductPage::PAGE) {
                $slug = $params->getString(ProductPageSubscribeParams::SLUG);
                if ($slug !== null) {
                    // Keep the string as a value even when PHP turns its array key into an integer.
                    $slugs[$slug] = $slug;
                    $routes[$index] = $slug;
                }
            } else {
                try {
                    $productId = $params->getPositiveInt(AdminProductPageSubscribeParams::PRODUCT_ID);
                } catch (InvalidPageRouteParamException) {
                    continue;
                }
                if ($productId !== null && ($cleared || isset($changed[$productId]))) {
                    $routes[$index] = $productId;
                }
            }
        }

        $matched = $this->productPageAddresses->matchingProducts($slugs, $cleared ? null : array_keys($changed));
        foreach ($routes as $index => $route) {
            if (is_int($route)) {
                if (!$this->productPageAddresses->primaryUnavailable()) {
                    $this->resendAddressedPageWhole($contexts[$index]);
                }
            } elseif (isset($matched[$route])) {
                $this->retryStorefrontCartDebt($contexts[$index], $matched[$route]);
            }
        }
    }

    /**
     * Confirms that the debt still belongs to the product selected by the cart fact.
     *
     * @param PageSubscriptionContext $context Storefront debt eligible for this cart fact
     * @param int $productId Product whose count changed
     */
    private function retryStorefrontCartDebt(PageSubscriptionContext $context, int $productId): void
    {
        if (!$context->isCurrent()) {
            return;
        }
        $slug = $context->subscription?->stringParams()[ProductPageSubscribeParams::SLUG] ?? null;
        if ($slug === null) {
            return;
        }

        try {
            $reading = $this->productReading($slug);
        } catch (HilosException) {
            // The address is still owed. A failed read cannot confirm the scope of the cart fact.
            return;
        }

        if ($reading?->product->id === $productId) {
            $this->resendAddressedPageWhole($context, reportMove: true);
        }
    }

    /**
     * @param array<int, array<string, array{PageSubscriptionContext, Product}>> $candidates Products and original comparison addresses
     */
    private function reconcileProductPages(array $candidates): void
    {
        $batches = $this->productPageAddresses->readBatches(
            array_keys($candidates),
            static fn (array $ids): array => Hilos::$db->productWarehouseStocks->storedUnitsOfProducts(...$ids),
            function (array $ids) use ($candidates): void {
                foreach ($ids as $id) {
                    $this->rememberUnresolvedProductPages(array_column($candidates[$id], 0));
                }
            },
        );

        foreach ($batches as $totals) {
            foreach ($totals as $productId => $units) {
                $subscribers = $candidates[$productId];
                [, $product] = reset($subscribers);
                try {
                    $reading = $this->productPageAddresses->readPrimary(
                        static fn (): ProductReading => new ProductReading($product, Hilos::$db->categories[$product->categoryId], $units),
                    );
                } catch (WiringRefusal) {
                    continue;
                } catch (HilosException $failure) {
                    Logger::error("Product page reconciliation category read failed for {$productId}: {$failure->getMessage()}");
                    if ($failure instanceof DatabaseException) {
                        $this->rememberUnresolvedProductPages(array_column($subscribers, 0));
                    }
                    continue;
                }

                foreach ($subscribers as [$context]) {
                    if (!$context->isCurrent()) {
                        continue;
                    }
                    $basis = $this->productPageBasis($context->router->pageResource($context->acceptKey));
                    if ($basis === null ? $reading->availability !== ProductAvailability::NOT_PLACED
                        : $reading->fingerprint() !== $basis->fingerprint
                    ) {
                        $this->resendAddressedPageWhole($context, reportMove: true);
                    }
                }
            }
        }
    }

    /**
     * @param array<array-key, PageSubscriptionContext> $contexts Candidates needing another read after a source fact
     */
    private function rememberUnresolvedProductPages(array $contexts): void
    {
        foreach ($contexts as $context) {
            if ($context->isCurrent()) {
                $this->unresolvedProductPages[$context->subscription] = true;
            }
        }
    }

    /**
     * @param ?PageResource $resource Bound resource of the current subscription
     * @return ?ProductPageBasis Comparison basis if the page still holds a successful answer
     */
    private function productPageBasis(?PageResource $resource): ?ProductPageBasis
    {
        return $resource === null ? null : ($this->productPageBases[$resource] ?? null);
    }

    /**
     * Resolves a guest cart fact against the active pair, keeping the pair from each image together.
     *
     * @param SourceChange $change Guest cart line or voucher fact
     * @param int $sessionId Owner of the active guest cart
     * @param int $cartEpoch Epoch of the active guest cart
     * @return bool Whether the fact belongs to the active pair
     * @throws HilosException When a sparse fact cannot be read
     */
    private function guestCartChangeMatches(SourceChange $change, int $sessionId, int $cartEpoch): bool
    {
        if ($change->mutationType === TableMutationType::Clear) {
            return true;
        }

        $isVoucher = $change->sourceKey === EcommerceShopDbContext::sessionVoucherCarts;
        [$owner, $epoch] = self::guestCartCoordinate($change->row, $isVoucher);
        [$previousOwner, $previousEpoch] = self::guestCartCoordinate($change->previous, $isVoucher);
        if ($previousOwner === $sessionId && $previousEpoch === $cartEpoch) {
            return true;
        }

        if ($owner === null || $epoch === null) {
            if ($change->mutationType === TableMutationType::Delete || ($isVoucher && $change->mutationType !== TableMutationType::Update)) {
                return false;
            }
            $stored = $isVoucher
                ? Hilos::$db->sessionVoucherCarts->storedSlotCoordinate((int)$change->sourceId)
                : Hilos::$db->sessionProductCarts->storedLineCoordinate((int)$change->sourceId);
            if ($stored === null || ($owner !== null && $owner !== $stored[0]) || ($epoch !== null && $epoch !== $stored[1])) {
                return false;
            }
            [$owner, $epoch] = $stored;
        }

        return ($owner === $sessionId && $epoch === $cartEpoch)
            || (($previousOwner ?? $owner) === $sessionId && ($previousEpoch ?? $epoch) === $cartEpoch);
    }

    /**
     * @param array<string, mixed> Image or diff of a guest cart row
     * @param bool $isVoucher Whether the row is a voucher binding
     * @return array{?int, ?int} Session and epoch provided by this image
     */
    private static function guestCartCoordinate(array $row, bool $isVoucher): array
    {
        $owner = $isVoucher
            ? ($row[ObjectSessionVoucherCart::sessionId] ?? $row[EntitySessionVoucherCart::session_id] ?? null)
            : ($row[ObjectSessionProductCart::sessionId] ?? $row[EntitySessionProductCart::session_id] ?? null);
        $epoch = $isVoucher
            ? ($row[ObjectSessionVoucherCart::cartEpoch] ?? $row[EntitySessionVoucherCart::cart_epoch] ?? null)
            : ($row[ObjectSessionProductCart::cartEpoch] ?? $row[EntitySessionProductCart::cart_epoch] ?? null);

        return [$owner === null ? null : (int)$owner, $epoch === null ? null : (int)$epoch];
    }

    /**
     * Resolves membership from the changed line without re-reading a deleted row.
     * A collection clear invalidates even a now-empty account cart.
     *
     * @param SourceChange $change Account cart line fact
     * @param int $ownerId User ID of the account cart
     * @return bool Whether the fact belongs to this cart
     * @throws HilosException When a configured cart collection refuses the read
     * @throws LogicException When the cart collection is misconfigured
     * @throws InvalidArgumentException When a loaded cart item has the wrong type
     * @throws DatabaseException When a sparse cart fact cannot be resolved
     */
    private function cartLineChangeMatchesOwner(SourceChange $change, int $ownerId): bool
    {
        if ($change->mutationType === TableMutationType::Clear) {
            return true;
        }

        $rowOwner = $change->row[ObjectUserProductCart::userId] ?? $change->row[EntityUserProductCart::user_id] ?? null;
        $previousOwner = $change->previous[ObjectUserProductCart::userId] ?? $change->previous[EntityUserProductCart::user_id] ?? null;
        if ((int) $rowOwner === $ownerId || (int) $previousOwner === $ownerId) {
            return true;
        }
        if ($rowOwner !== null || $change->mutationType === TableMutationType::Delete) {
            return false;
        }

        return (Hilos::$db->userProductCarts[(int) $change->sourceId] ?? null)?->userId === $ownerId;
    }

    /**
     * @param SourceChange $change Applied warehouse stock fact
     * @return list<int> Products named by the stock fact or its live row
     * @throws HilosException When the stock collection refuses a read
     * @throws LogicException When the stock collection is misconfigured
     * @throws InvalidArgumentException When the stock lookup query is invalid
     * @throws DatabaseException When a sparse stock fact cannot be resolved
     */
    private function cartProductIdsOfStockChange(SourceChange $change): array
    {
        $row = $change->row;
        $previous = $change->previous;
        $productId = $row[ObjectProductWarehouseStock::productId] ?? $row[EntityProductWarehouseStock::product_id] ?? null;
        $previousId = $previous[ObjectProductWarehouseStock::productId] ?? $previous[EntityProductWarehouseStock::product_id] ?? null;
        if ($productId === null && $change->mutationType !== TableMutationType::Delete) {
            $productId = Hilos::$db->productWarehouseStocks->productIdAtStockKey((int) $change->sourceId);
        }

        $productIds = [];
        foreach ([$productId, $previousId] as $id) {
            if ($id !== null) {
                $productIds[(int) $id] = true;
            }
        }

        return array_keys($productIds);
    }

    /**
     * @param SourceChange $fact Applied warehouse stock change
     * @return ?int Product key if the stock fact names it
     */
    private function productIdOfStockFact(SourceChange $fact): ?int
    {
        $row = $fact->row;
        $previous = $fact->previous;
        $productId = $row[ObjectProductWarehouseStock::productId] ?? $row[EntityProductWarehouseStock::product_id]
            ?? $previous[ObjectProductWarehouseStock::productId] ?? $previous[EntityProductWarehouseStock::product_id] ?? null;

        return is_int($productId) || is_string($productId) ? (int) $productId : null;
    }
}
