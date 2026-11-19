<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\CategoriesActions;
use Demo\EcommerceShop\Database\Object\Collection\Categories as ObjectCategories;
use Demo\EcommerceShop\Database\View\Item\Category;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Categories - Db collection of Category items.
 *
 * @extends DbCollection<Category, ObjectCategories>
 * @method ObjectCategories|null getObjectCollection()
 * @method Category|null current()
 * @method Category|null first()
 * @method Category|null last()
 * @method Category offsetGet(mixed $offset)
 * @property-read CategoriesActions $actions Collection actions (create); items use CategoryActions
 */
final class Categories extends DbCollection
{
    public const string DB_ITEM_CLASS = Category::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCategories::class;
}
