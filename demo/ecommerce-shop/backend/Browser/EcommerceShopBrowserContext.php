<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser;

use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Browser\List\MainCategoriesBrowserList;
use Demo\EcommerceShop\Browser\List\MainProductsBrowserList;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;

/**
 * EcommerceShopBrowserContext - Browser-facing context ($browser layer) for ecommerce-shop.
 *
 * Says who is behind a socket - the admin pages ask it through the page access gate - and
 * computes the two fields of the home page: the address of a category's tile picture and whether
 * a product is on the shop front; and the two of the product card on the admin_product page: the
 * address of the product's picture and what the warehouses keep of it. A person's presence and
 * session count, the password fields and the scheduled deletion's date are the framework's,
 * computed for its card of one person (HIL-1254), and the other admin tables go out through the
 * framework self-snapshot path.
 */
final class EcommerceShopBrowserContext extends BrowserContext
{
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
                | CollectionNotManualException $exception
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
}
