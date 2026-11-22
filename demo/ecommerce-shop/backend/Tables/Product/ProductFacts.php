<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Product;

use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\DatabaseException;

/** Resolves the products named by a table row key or a stock change. */
final class ProductFacts
{
    /**
     * Reads the product a row key names.
     *
     * @param string|int $rowKey Decimal product id
     * @return ?Product The product, or null when the key is no product id or no product holds it
     * @throws DatabaseException When the product cannot be read
     * @throws LogicException When the products collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     */
    public static function productOfKey(string|int $rowKey): ?Product
    {
        $id = filter_var($rowKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || (string) $id !== (string) $rowKey || !isset(Hilos::$db->products[$id])) {
            return null;
        }

        return Hilos::$db->products[$id];
    }

    /**
     * The products a stock fact names that still exist, each once.
     *
     * @param SourceChange $change Fact of a stock row
     * @return list<Product> Products named by the current row, the fact's row or its previous row
     * @throws DatabaseException When a stock row or a product cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     */
    public static function productsOfStockFact(SourceChange $change): array
    {
        $stock = $change->mutationType === TableMutationType::Delete
            ? null
            : Hilos::$db->productWarehouseStocks[(int) $change->sourceId] ?? null;
        $productIds = [];
        foreach ([$stock?->toArray() ?? [], $change->row, $change->previous] as $row) {
            $id = $row[ObjectProductWarehouseStock::productId] ?? $row[EntityProductWarehouseStock::product_id] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $productIds[(int) $id] = true;
            }
        }

        $products = [];
        foreach (array_keys($productIds) as $productId) {
            if (isset(Hilos::$db->products[$productId])) {
                $products[] = Hilos::$db->products[$productId];
            }
        }

        return $products;
    }
}
