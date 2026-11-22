<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Object\Collection\ProductSlugs as ObjectProductSlugs;
use Demo\EcommerceShop\Database\Object\Item\ProductSlug as ObjectProductSlug;
use Demo\EcommerceShop\Database\View\Collection\ProductSlugs as DbCollectionProductSlugs;
use Demo\EcommerceShop\Database\View\Item\ProductSlug;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\HilosException;

/**
 * ProductSlugsActions - write operations for the addresses of products.
 *
 * Called by the actions of a product inside their transaction. An address remains with its
 * product through edits. The test fixture command removes all addresses of its product.
 *
 * @extends DbActions<ProductSlug, ObjectProductSlugs>
 * @property-read DbCollectionProductSlugs $collection
 * @property-read ObjectProductSlugs $objectCollection
 */
final class ProductSlugsActions extends DbActions
{
    /**
     * Records that a product is reached by this address.
     *
     * An address the product already has is left as it is: a product renamed back takes its
     * old address again.
     *
     * @param string $slug Address picked for the product
     * @param int $productId Id of the product
     * @throws CreateNotAllowedException When this process may not add an address
     * @throws LogicException When the address belongs to another product
     * @throws CollectionNotFullyLoadedException When this process does not hold every address
     * @throws DatabaseException When collection loading or the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function record(string $slug, int $productId): void
    {
        $this->ensureCanCreate();

        foreach ($this->collection as $address) {
            if ($address->slug !== $slug) {
                continue;
            }
            if ($address->productId === $productId) {
                return;
            }
            throw new LogicException("Address {$slug} is not free for product {$productId}");
        }

        $address = ObjectProductSlug::create();
        $address->slug = $slug;
        $address->productId = $productId;
        $address->sync();
        $this->addObjectToCollection($address);
    }

    /**
     * Removes the fixture's addresses inside its product deletion transaction.
     *
     * @param int $productId Id of the product fixture
     * @throws WriteNotAllowedException When this process may not remove the addresses
     * @throws ObjectCollectionNullException When the object collection is missing
     * @throws CollectionNotFullyLoadedException When this process does not hold every address
     * @throws LogicException When the collection's entity class is missing
     * @throws DatabaseException When loading or deleting addresses fails
     * @throws InvalidArgumentException When the DB sync signal cannot be named
     * @throws SourceChangeSubscriberException When a subscriber to the removal announcement fails
     */
    public function deleteFixtureAddresses(int $productId): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $addresses = $this->objectCollection;
        foreach ($addresses as $key => $address) {
            if ($address->productId !== $productId) {
                continue;
            }
            $address->delete();
            unset($addresses[$key]);
        }
    }
}
