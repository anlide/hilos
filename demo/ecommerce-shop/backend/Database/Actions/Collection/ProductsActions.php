<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\ProductFields;
use Demo\EcommerceShop\Database\Entity\Item\Category;
use Demo\EcommerceShop\Database\Object\Collection\Products as ObjectProducts;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\View\Collection\Products as DbCollectionProducts;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Hilos;
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
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use Throwable;

/**
 * ProductsActions - write operations for the Products collection.
 *
 * @extends DbActions<Product, ObjectProducts>
 * @property-read DbCollectionProducts $collection
 * @property-read ObjectProducts $objectCollection
 */
final class ProductsActions extends DbActions
{
    /**
     * Creates a product and records its address.
     *
     * Everything is checked and derived before the first write: the fields, the category, the
     * picture and the address. The row and its address are written in one transaction, and the
     * picture is bound only once it commits. Two products may share a name; the address tells
     * them apart. A category deleted after the check is refused by the database's reference to it,
     * in the same words as the check.
     *
     * @param string $name Product name
     * @param ?int $categoryId Category the product is in, null when none is chosen
     * @param ProductKind $kind Kind of the product, kept for good
     * @param int $price Price in kopecks
     * @param string $description Text describing the product
     * @param int $pictureFileId Registry file the product shows, published for it alone
     * @return Product Created product Db item
     * @throws EmptyValueException When the name or the description is empty, or no category is chosen
     * @throws ValueTooLongException When the name or the description is longer than its column
     * @throws ValidationException When the price is out of range or the category no longer exists
     * @throws LogicException When another product already shows the picture, or the files door is missing
     * @throws CollectionNotFullyLoadedException When this process does not hold every product and address
     * @throws CreateNotAllowedException When this process may not add a product or its address
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading, the insert or the commit fails
     * @throws HilosException When the right, the database or the collection refuses the write
     * @throws Throwable Whatever the transaction raises, preserved through its rollback
     */
    public function create(
        string $name,
        ?int $categoryId,
        ProductKind $kind,
        int $price,
        string $description,
        int $pictureFileId,
    ): Product {
        $this->ensureCanCreate();

        $name = ProductFields::name($name);
        $categoryId = ProductFields::category($categoryId);
        $price = ProductFields::price($price);
        $description = ProductFields::description($description);
        $pictureFileId = ProductFields::picture($pictureFileId, null);
        $slug = ProductFields::slug($name, null);
        $files = Hilos::$files ?? throw new LogicException('The files door is missing');

        $product = ObjectProduct::create();
        try {
            Database::transaction(
                DatabaseNames::PRIMARY,
                function () use ($product, $name, $categoryId, $kind, $price, $description, $pictureFileId, $slug, $files): void {
                    $product->name = $name;
                    $product->categoryId = $categoryId;
                    $product->kind = $kind->value;
                    $product->price = $price;
                    $product->description = $description;
                    $product->pictureFileId = $pictureFileId;
                    $product->slug = $slug;
                    $product->changedAt = TimeHelper::nowMs();
                    $product->sync();
                    $this->addObjectToCollection($product);

                    Hilos::$db->productSlugs->actions->record(
                        $slug,
                        $product->id ?? throw new LogicException('The saved product has no id'),
                    );
                    Database::afterCommit(static fn () => $files->markBound([$pictureFileId]));
                },
            );
        } catch (ForeignKeyConstraintException $e) {
            if ($e->referencedTable() !== Category::_table) {
                throw $e;
            }

            throw new ValidationException('This category no longer exists', previous: $e);
        }

        return $this->createDbItemFromObject($product);
    }
}
