<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Object\Collection\CategorySlugs as ObjectCategorySlugs;
use Demo\EcommerceShop\Database\Object\Item\CategorySlug as ObjectCategorySlug;
use Demo\EcommerceShop\Database\View\Collection\CategorySlugs as DbCollectionCategorySlugs;
use Demo\EcommerceShop\Database\View\Item\CategorySlug;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;

/**
 * CategorySlugsActions - write operations for the addresses of categories.
 *
 * Called by the actions of a category alone, inside their transaction: an address is recorded
 * when a category gets it and taken out of use when the category is deleted. A row is never
 * removed, so an address once given leads to its category or to nobody.
 *
 * @extends DbActions<CategorySlug, ObjectCategorySlugs>
 * @property-read DbCollectionCategorySlugs $collection
 * @property-read ObjectCategorySlugs $objectCollection
 */
final class CategorySlugsActions extends DbActions
{
    /**
     * Records that a category is reached by this address.
     *
     * An address the category already has is left as it is: a category renamed back takes its
     * old address again.
     *
     * @param string $slug Address picked for the category
     * @param int $categoryId Id of the category
     * @throws CreateNotAllowedException When this process may not add an address
     * @throws LogicException When the address belongs to another category or is out of use
     * @throws DatabaseException When collection loading or the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function record(string $slug, int $categoryId): void
    {
        $this->ensureCanCreate();

        foreach ($this->collection as $address) {
            if ($address->slug !== $slug) {
                continue;
            }
            if ($address->categoryId === $categoryId) {
                return;
            }
            throw new LogicException("Address {$slug} is not free for category {$categoryId}");
        }

        $address = ObjectCategorySlug::create();
        $address->slug = $slug;
        $address->categoryId = $categoryId;
        $address->sync();
        $this->addObjectToCollection($address);
    }

    /**
     * Takes every address of a category out of use, before the category is deleted.
     *
     * @param int $categoryId Id of the category being deleted
     * @throws ObjectCollectionNullException If the collection holds no object collection
     * @throws WriteNotAllowedException When this process may not write the addresses
     * @throws DatabaseException When collection loading or an update fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws HilosException When the right, the database or the announcement refuses the save
     */
    public function retire(int $categoryId): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);

        foreach ($this->objectCollection as $address) {
            if ($address->categoryId !== $categoryId) {
                continue;
            }
            $address->categoryId = null;
            $address->sync();
        }
    }
}
