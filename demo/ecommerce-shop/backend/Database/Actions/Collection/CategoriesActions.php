<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Actions\CategoryFields;
use Demo\EcommerceShop\Database\Object\CatalogPictureUsage;
use Demo\EcommerceShop\Database\Object\Collection\Categories as ObjectCategories;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Database\View\Collection\Categories as DbCollectionCategories;
use Demo\EcommerceShop\Database\View\Item\Category;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\HilosException;
use Throwable;

/**
 * CategoriesActions - write operations for the Categories collection.
 *
 * @extends DbActions<Category, ObjectCategories>
 * @property-read DbCollectionCategories $collection
 * @property-read ObjectCategories $objectCollection
 */
final class CategoriesActions extends DbActions
{
    /**
     * Creates a category at the end of the catalog and records its address.
     *
     * Everything is checked and derived before the first write: the fields, the address and
     * the place. The row and its address are written in one transaction, and the picture is
     * bound only once it commits. A name already taken is refused by the UNIQUE index rather
     * than by a read before the write.
     *
     * @param string $name Category name, unique across the shop
     * @param string $description Line describing the category
     * @param int $pictureFileId Registry file the category shows, published for it alone
     * @param ?int $pictureOwnerUserId Expected publisher for a browser request, null for an internal call
     * @return Category Created category Db item
     * @throws EmptyValueException When the name or the description is empty
     * @throws ValueTooLongException When the name or the description is longer than its column
     * @throws DuplicateValueException When another category already has this name
     * @throws ValidationException When the picture is unavailable
     * @throws LogicException When the address or the files door cannot be used
     * @throws CreateNotAllowedException When this process may not add a category or its address
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading, the insert or the commit fails
     * @throws HilosException When the right, the database or the collection refuses the write
     * @throws Throwable Whatever the transaction raises, preserved through its rollback
     */
    public function create(string $name, string $description, int $pictureFileId, ?int $pictureOwnerUserId = null): Category
    {
        $this->ensureCanCreate();

        $name = CategoryFields::name($name);
        $description = CategoryFields::description($description);
        $slug = CategoryFields::slug($name, null);
        $position = $this->lastPosition() + 1;
        $files = Hilos::$files ?? throw new LogicException('The files door is missing');

        $category = ObjectCategory::create();
        Database::transaction(
            DatabaseNames::PRIMARY,
            function () use ($category, $name, $description, $slug, $position, $pictureFileId, $pictureOwnerUserId, $files): void {
                CatalogPictureUsage::lockFresh($pictureFileId, $pictureOwnerUserId);
                $category->name = $name;
                $category->description = $description;
                $category->slug = $slug;
                $category->position = $position;
                $category->pictureFileId = $pictureFileId;
                try {
                    $category->sync();
                } catch (DuplicateEntryException) {
                    // The address and the picture are free by the checks above. Report the taken name.
                    throw new DuplicateValueException('A category with this name already exists');
                }
                $this->addObjectToCollection($category);

                Hilos::$db->categorySlugs->actions->record(
                    $slug,
                    $category->id ?? throw new LogicException('The saved category has no id'),
                );
                Database::afterCommit(static fn () => $files->markBound([$pictureFileId]));
            },
        );

        return $this->createDbItemFromObject($category);
    }

    /**
     * @return int Place of the last category of the catalog, 0 when there is none
     */
    private function lastPosition(): int
    {
        $last = 0;
        foreach ($this->collection as $category) {
            $last = max($last, $category->position);
        }

        return $last;
    }
}
