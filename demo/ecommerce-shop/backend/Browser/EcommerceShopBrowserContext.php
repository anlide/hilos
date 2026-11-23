<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser;

use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Browser\List\MainCategoriesBrowserList;
use Demo\EcommerceShop\Browser\List\MainProductsBrowserList;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\ShopFront\ProductReading;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageResource;
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
use Hilos\Utils\Logger;
use Hilos\WiringRefusal;
use JsonException;
use WeakMap;

/**
 * EcommerceShopBrowserContext - Browser-facing context ($browser layer) for ecommerce-shop.
 *
 * Says who is behind a socket - the admin pages ask it through the page access gate - and
 * computes the two fields of the home page: the address of a category's tile picture and whether
 * a product is on the shop front; and the fields of the product card on the admin_product page:
 * the picture's address, the edit version, and what the warehouses keep of it. A person's presence and
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
        }
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
     * Publishes one complete card per affected subscription from the final state of the worker flush.
     *
     * @param bool $databaseBack Whether this worker recovered its database connection
     */
    protected function publishLocalPageFacts(bool $databaseBack): void
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
            if ($subscription->page !== PageConstants::PRODUCT) {
                continue;
            }
            $slug = $subscription->stringParams()[ProductPageSubscribeParams::SLUG] ?? null;
            if ($slug === null || $slug === '') {
                continue;
            }
            $resource = $router->pageResource($acceptKey);
            if ($resource?->collectionKey === EcommerceShopDbContext::products) {
                $bound[(int) $resource->idString][$acceptKey] = $subscription;
            } elseif ($resource === null && $router->hasPageDeliveryFailure($acceptKey)) {
                $unbound[$slug][$acceptKey] = $subscription;
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
            try {
                $productId ??= Hilos::$db->productWarehouseStocks->productIdAtStockKey($stockId);
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

        $reconcile = [];
        foreach ($bound as $productId => $subscribers) {
            $changed = ($products[$productId] ?? TableMutationType::Create) === TableMutationType::Update;
            $read = $changed || $categories !== [];
            foreach ($subscribers as $subscription) {
                $read = $read || isset($retry[$subscription]);
                if ($unresolvedStock) {
                    $this->unresolvedProductPages[$subscription] = true;
                }
            }
            if (!$read) {
                continue;
            }

            try {
                $product = Hilos::$db->products[$productId] ?? null;
            } catch (WiringRefusal) {
                continue;
            } catch (HilosException $failure) {
                $uncertain = false;
                foreach ($subscribers as $acceptKey => $subscription) {
                    $basis = $this->productPageBasis($router->pageResource($acceptKey));
                    if ($changed || ($basis !== null && isset($categories[$basis->categoryId]))) {
                        $this->failPublication(PageConstants::PRODUCT, $acceptKey, $failure);
                    } else {
                        $uncertain = $uncertain || !$router->hasPageDeliveryFailure($acceptKey);
                        if ($failure instanceof DatabaseException) {
                            $this->unresolvedProductPages[$subscription] = true;
                        }
                    }
                }
                if ($uncertain) {
                    Logger::error("Product page product lookup failed for {$productId}: {$failure->getMessage()}");
                }
                continue;
            }
            if ($product === null) {
                continue;
            }
            foreach ($subscribers as $acceptKey => $subscription) {
                if (!$subscription->paramEquals(ProductPageSubscribeParams::SLUG, $product->slug)) {
                    continue;
                }
                if ($changed || isset($categories[$product->categoryId])) {
                    $this->resendPageWhole(PageConstants::PRODUCT, $acceptKey);
                } elseif (isset($retry[$subscription])) {
                    $reconcile[$productId][$acceptKey] = $product;
                }
            }
        }

        if ($unbound !== []) {
            $slugs = [];
            foreach ($unbound as $slug => $subscribers) {
                foreach ($subscribers as $subscription) {
                    if ($unresolvedStock) {
                        $this->unresolvedProductPages[$subscription] = true;
                    }
                    if ($products !== [] || $categories !== [] || isset($retry[$subscription])) {
                        // PHP converts integer-shaped array keys, while the ORM needs string values for this column.
                        $slugs[$slug] = (string) $slug;
                    }
                }
            }
            try {
                foreach (Hilos::$db->products->whereColumnIn(EntityProduct::slug, ...array_values($slugs)) as $product) {
                    // An SQL collation can find an address that is not an exact browser slug.
                    foreach ($unbound[$product->slug] ?? [] as $acceptKey => $subscription) {
                        if (isset($products[$product->id]) || isset($categories[$product->categoryId])) {
                            $this->resendPageWhole(PageConstants::PRODUCT, $acceptKey);
                        } elseif (isset($retry[$subscription])) {
                            $reconcile[$product->id][$acceptKey] = $product;
                        }
                    }
                }
            } catch (WiringRefusal) {
                // The database context already reported the missing read interest or readiness.
            } catch (HilosException $failure) {
                Logger::error("Product page slug lookup failed: {$failure->getMessage()}");
                if ($failure instanceof DatabaseException) {
                    foreach ($slugs as $slug) {
                        foreach ($unbound[$slug] as $subscription) {
                            $this->unresolvedProductPages[$subscription] = true;
                        }
                    }
                }
            }
        }

        $this->reconcileProductPages($reconcile);
    }

    /**
     * Tries a previously uncertain candidate when its exact card has changed or become visible.
     *
     * @param array<int, array<string, Product>> $candidates Product rows and the subscriptions still awaiting a comparison
     */
    private function reconcileProductPages(array $candidates): void
    {
        $router = Hilos::$sr;
        if ($candidates === [] || $router === null) {
            return;
        }

        try {
            $totals = Hilos::$db->productWarehouseStocks->totalsOfProducts(...array_keys($candidates));
        } catch (WiringRefusal) {
            return;
        } catch (HilosException $failure) {
            Logger::error("Product page reconciliation stock read failed: {$failure->getMessage()}");
            if ($failure instanceof DatabaseException) {
                foreach ($candidates as $subscribers) {
                    foreach ($subscribers as $acceptKey => $product) {
                        $subscription = $router->pageSubscription($acceptKey);
                        if ($subscription !== null) {
                            $this->unresolvedProductPages[$subscription] = true;
                        }
                    }
                }
            }
            return;
        }

        foreach ($candidates as $productId => $subscribers) {
            $units = $totals->of($productId)->unitCount;
            foreach ($subscribers as $acceptKey => $product) {
                $basis = $this->productPageBasis($router->pageResource($acceptKey));
                try {
                    $reading = new ProductReading($product, Hilos::$db->categories[$product->categoryId], $units);
                } catch (WiringRefusal) {
                    continue;
                } catch (HilosException $failure) {
                    Logger::error("Product page reconciliation category read failed for {$productId}: {$failure->getMessage()}");
                    $subscription = $router->pageSubscription($acceptKey);
                    if ($failure instanceof DatabaseException && $subscription !== null) {
                        $this->unresolvedProductPages[$subscription] = true;
                    }
                    continue;
                }

                if ($basis === null ? $reading->availability !== ProductAvailability::NOT_PLACED
                    : $reading->fingerprint() !== $basis->fingerprint
                ) {
                    $this->resendPageWhole(PageConstants::PRODUCT, $acceptKey);
                }
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
