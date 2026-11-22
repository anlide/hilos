<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Item;

use Demo\EcommerceShop\Database\Actions\CategoryFields;
use Demo\EcommerceShop\Database\Entity\Item\Product;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Database\View\Item\Category;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\HilosException;
use Throwable;

/**
 * CategoryActions - write operations for a single Category item.
 *
 * Every write runs in one transaction, and a refused one leaves the category, the other
 * categories and the addresses as they were: the rollback puts the objects and the collections
 * back. The files library hears of a picture only once the write commits.
 *
 * @extends DbActions<Category, ObjectCategory>
 * @property-read ObjectCategory $object
 */
final class CategoryActions extends DbActions
{
    /**
     * Renames the category, rewrites its description and may show another picture.
     *
     * Everything is checked and derived before the first field is assigned: both fields, the
     * address and the picture. A new address is recorded beside the old ones, which keep leading
     * here. A new picture is bound once the edit commits, and the former one is removed then.
     *
     * @param string $name New category name, unique across the shop
     * @param string $description New line describing the category
     * @param ?int $pictureFileId Registry file to show instead, null to keep the current one
     * @throws ItemNotFoundForUpdateException If the category id is null (not persisted)
     * @throws EmptyValueException When the name or the description is empty
     * @throws ValueTooLongException When the name or the description is longer than its column
     * @throws DuplicateValueException When another category already has this name
     * @throws LogicException When another category already shows the picture, or the files door is missing
     * @throws WriteNotAllowedException When this process may not write the category
     * @throws CreateNotAllowedException When this process may not record the new address
     * @throws DatabaseException When the update or the commit fails
     * @throws HilosException When the right, the database or the announcement refuses the save
     * @throws Throwable Whatever the transaction raises, preserved through its rollback
     */
    public function update(string $name, string $description, ?int $pictureFileId): void
    {
        $this->ensureCanWrite();

        $categoryId = $this->object->id
            ?? throw new ItemNotFoundForUpdateException('Category not found for update (id is null)');

        $name = CategoryFields::name($name);
        $description = CategoryFields::description($description);
        $slug = CategoryFields::slug($name, $categoryId);
        $slugChanged = $slug !== $this->object->slug;
        $formerPictureFileId = $this->object->pictureFileId;
        $pictureFileId = $pictureFileId === null || $pictureFileId === $formerPictureFileId
            ? $formerPictureFileId
            : CategoryFields::picture($pictureFileId, $categoryId);
        $files = Hilos::$files ?? throw new LogicException('The files door is missing');

        Database::transaction(
            DatabaseNames::PRIMARY,
            function () use ($categoryId, $name, $description, $slug, $slugChanged, $pictureFileId, $formerPictureFileId, $files): void {
                $this->object->name = $name;
                $this->object->description = $description;
                $this->object->slug = $slug;
                $this->object->pictureFileId = $pictureFileId;
                try {
                    $this->object->sync();
                } catch (DuplicateEntryException) {
                    // The address and the picture are free by the checks above; the name is what is left.
                    throw new DuplicateValueException('A category with this name already exists');
                }

                if ($slugChanged) {
                    Hilos::$db->categorySlugs->actions->record($slug, $categoryId);
                }
                if ($pictureFileId !== $formerPictureFileId) {
                    // Two announcements, so that a failed bind does not keep the former file.
                    Database::afterCommit(static fn () => $files->markBound([$pictureFileId]));
                    Database::afterCommit(static fn () => $files->remove([$formerPictureFileId]));
                }
            },
        );
    }

    /**
     * Swaps the category with the one right before it in the catalog's order.
     *
     * @throws ItemNotFoundForUpdateException If the category id is null (not persisted)
     * @throws ObjectCollectionNullException If object collection is null
     * @throws ValidationException When no category comes before this one
     * @throws WriteNotAllowedException When this process may not write either category
     * @throws DatabaseException When an update or the commit fails
     * @throws HilosException When the right, the database or the announcement refuses the save
     * @throws Throwable Whatever the transaction raises, preserved through its rollback
     */
    public function moveUp(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Category not found for move (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');

        $position = $this->object->position;
        $previous = null;
        foreach ($objectCollection as $category) {
            if ($category->position < $position && ($previous === null || $category->position > $previous->position)) {
                $previous = $category;
            }
        }
        if ($previous === null) {
            throw new ValidationException('This category is already first');
        }

        Database::transaction(DatabaseNames::PRIMARY, function () use ($previous, $position): void {
            $this->object->position = $previous->position;
            $previous->position = $position;
            $previous->sync();
            $this->object->sync();
        });
    }

    /**
     * Deletes the category and takes its addresses out of use for good.
     *
     * The addresses stay, belonging to nobody, so none of them is ever given to another
     * category. The picture is removed once the delete commits.
     *
     * Only an empty category goes, and the database judges what empty is: its reference from the
     * products refuses the delete while a product is still in the category, so the products are
     * not read first and a product written a moment before the delete still holds the category.
     * The refusal rolls the retired addresses back, and the picture stays.
     *
     * @throws ItemNotFoundForDeleteException If the category id is null (not persisted)
     * @throws ObjectCollectionNullException If object collection is null
     * @throws LogicException When the files door is missing
     * @throws ValidationException When a product is still in the category
     * @throws WriteNotAllowedException When this process may not remove the category or write its addresses
     * @throws DatabaseException When the delete or the commit fails
     * @throws HilosException On database error
     * @throws Throwable Whatever the transaction raises, preserved through its rollback
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $categoryId = $this->object->id
            ?? throw new ItemNotFoundForDeleteException('Category not found for delete (id is null)');

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $files = Hilos::$files ?? throw new LogicException('The files door is missing');
        $pictureFileId = $this->object->pictureFileId;

        try {
            Database::transaction(
                DatabaseNames::PRIMARY,
                function () use ($categoryId, $objectCollection, $files, $pictureFileId): void {
                    Hilos::$db->categorySlugs->actions->retire($categoryId);

                    $idString = $this->object->getIdString();
                    $this->object->delete();
                    unset($objectCollection[$idString]);

                    Database::afterCommit(static fn () => $files->remove([$pictureFileId]));
                },
            );
        } catch (ForeignKeyConstraintException $e) {
            if ($e->referencingTable() !== Product::_table) {
                throw $e;
            }

            throw new ValidationException('This category has products and cannot be deleted', previous: $e);
        }
    }
}
