<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Item;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\ProductFields;
use Demo\EcommerceShop\Database\Entity\Item\Category;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use Throwable;

/**
 * ProductActions - write operations for a single Product item.
 *
 * A product is never deleted. An edit runs in one transaction, and a refused one leaves the
 * product and the addresses as they were: the rollback puts the object and the collections
 * back. The files library hears of a picture only once the edit commits.
 *
 * @extends DbActions<Product, ObjectProduct>
 * @property-read ObjectProduct $object
 */
final class ProductActions extends DbActions
{
    /**
     * Rewrites the product's fields and may show another picture.
     *
     * The kind is checked first and never changes. Everything else is checked and derived before
     * the first field is assigned: the fields, the category, the address and the picture. An
     * edit that changes nothing writes nothing: no row, no journal line, no word to the files
     * library. A new address is recorded beside the old ones, which keep leading here. A new
     * picture is bound once the edit commits, and the former one is removed then. A category
     * deleted after the check is refused by the database's reference to it, in the same words as
     * the check.
     *
     * @param string $name New product name
     * @param ?int $categoryId Category the product is in, null when none is chosen
     * @param ProductKind $kind Kind of the product, which must be the one it was created with
     * @param int $price New price in kopecks
     * @param string $description New text describing the product
     * @param ?int $pictureFileId Registry file to show instead, null to keep the current one
     * @throws ItemNotFoundForUpdateException If the product id is null (not persisted)
     * @throws ValidationException When the kind differs, the price is out of range or the category no longer exists
     * @throws EmptyValueException When the name or the description is empty, or no category is chosen
     * @throws ValueTooLongException When the name or the description is longer than its column
     * @throws LogicException When another product already shows the picture, or the files door is missing
     * @throws CollectionNotFullyLoadedException When this process does not hold every product and address
     * @throws WriteNotAllowedException When this process may not write the product
     * @throws CreateNotAllowedException When this process may not record the new address
     * @throws DatabaseException When the update or the commit fails
     * @throws HilosException When the right, the database or the announcement refuses the save
     * @throws Throwable Whatever the transaction raises, preserved through its rollback
     */
    public function update(
        string $name,
        ?int $categoryId,
        ProductKind $kind,
        int $price,
        string $description,
        ?int $pictureFileId,
    ): void {
        $this->ensureCanWrite();

        $productId = $this->object->id
            ?? throw new ItemNotFoundForUpdateException('Product not found for update (id is null)');
        if ($kind->value !== $this->object->kind) {
            throw new ValidationException('A product keeps the kind it was created with');
        }

        $name = ProductFields::name($name);
        $categoryId = ProductFields::category($categoryId);
        $price = ProductFields::price($price);
        $description = ProductFields::description($description);
        $slug = ProductFields::slug($name, $productId);
        $slugChanged = $slug !== $this->object->slug;
        $formerPictureFileId = $this->object->pictureFileId;
        $pictureFileId = $pictureFileId === null || $pictureFileId === $formerPictureFileId
            ? $formerPictureFileId
            : ProductFields::picture($pictureFileId, $productId);

        if (
            $name === $this->object->name
            && $categoryId === $this->object->categoryId
            && $price === $this->object->price
            && $description === $this->object->description
            && !$slugChanged
            && $pictureFileId === $formerPictureFileId
        ) {
            return;
        }
        $files = Hilos::$files ?? throw new LogicException('The files door is missing');

        try {
            Database::transaction(
                DatabaseNames::PRIMARY,
                function () use (
                    $productId,
                    $name,
                    $categoryId,
                    $price,
                    $description,
                    $slug,
                    $slugChanged,
                    $pictureFileId,
                    $formerPictureFileId,
                    $files,
                ): void {
                    $this->object->name = $name;
                    $this->object->categoryId = $categoryId;
                    $this->object->price = $price;
                    $this->object->description = $description;
                    $this->object->slug = $slug;
                    $this->object->pictureFileId = $pictureFileId;
                    $this->object->changedAt = TimeHelper::nowMs();
                    $this->object->sync();

                    if ($slugChanged) {
                        Hilos::$db->productSlugs->actions->record($slug, $productId);
                    }
                    if ($pictureFileId !== $formerPictureFileId) {
                        // Two announcements, so that a failed bind does not keep the former file.
                        Database::afterCommit(static fn () => $files->markBound([$pictureFileId]));
                        Database::afterCommit(static fn () => $files->remove([$formerPictureFileId]));
                    }
                },
            );
        } catch (ForeignKeyConstraintException $e) {
            if ($e->referencedTable() !== Category::_table) {
                throw $e;
            }

            throw new ValidationException('This category no longer exists', previous: $e);
        }
    }
}
