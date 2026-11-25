<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * CartLineRules - the one place the line of a cart is checked.
 *
 * A line says how many units of one product a cart holds. The cart of a visitor without an
 * account and the cart of an account take the same products under the same rules and refuse
 * with the same words, so both ask here what a line becomes; each cart writes its own rows.
 *
 * The stock of a product is the sum of its units over every warehouse; a unique piece is in
 * stock only while a warehouse keeps it. No change a person asks for takes a line above the
 * stock. A line ends up above it only by itself - the stock fell, or two carts were joined - and
 * then it may still go down. A call that changes nothing gets back the quantity it gave, and the
 * cart writes nothing.
 */
final class CartLineRules
{
    /** A step of one unit up. */
    public const int STEP_UP = 1;

    /** A step of one unit down. */
    public const int STEP_DOWN = -1;

    /** Fewest units a line keeps: below it the line is taken out, not kept empty. */
    public const int QUANTITY_MIN = 1;

    /** Largest quantity the cart tables' unsigned INT columns can store. */
    public const int QUANTITY_MAX = 4_294_967_295;

    /** Refusal shared by stale cart bindings and lines outside the current cart. */
    public const string LINE_UNAVAILABLE = 'This cart item is no longer available.';

    private const string QUANTITY_BELOW_MINIMUM = 'A cart line keeps one unit at least: Remove takes it out';

    /**
     * Returns the quantity of a line after one unit of its product is put in the cart.
     *
     * A stock item gets a line of one unit, or one unit more on the line it has. A unique piece
     * gets a line of its one unit, and one already in the cart stays as it is. Both checks use
     * stored stock because the Add can reach the cart writer before a stock change is synced.
     *
     * @param int $productId Id of the product
     * @param ?int $quantity Quantity of its line now, or null when the cart has none
     * @return int Quantity of the line from now on
     * @throws ValidationException When the product does not exist, a unique piece is not in a warehouse yet, or a
     *     stock item has no unit left for one more
     * @throws LogicException When the stock collection class constants are not configured
     * @throws InvalidArgumentException When a loaded stock object does not match its collection
     * @throws DatabaseException When the stock cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched stock item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched stock item is rejected by a non-manual collection
     * @throws HilosException When the products cannot be read
     */
    public static function added(int $productId, ?int $quantity): int
    {
        if (self::productKind($productId) === ProductKind::UNIQUE) {
            if (Hilos::$db->productWarehouseStocks->storedUnitsOfProduct($productId) === 0) {
                throw new ValidationException('This piece is not in a warehouse yet');
            }

            return $quantity ?? self::QUANTITY_MIN;
        }

        $added = $quantity === null ? self::QUANTITY_MIN : $quantity + self::STEP_UP;
        self::checkInStock($productId, $added, readStoredStock: true);

        return $added;
    }

    /**
     * Returns the quantity of a stock item's line after a step of one unit up or down.
     *
     * A step down is let through above the stock, which is how a line left above it comes back.
     *
     * @param int $productId Id of the product
     * @param ?int $quantity Quantity of its line now, or null when the cart has none
     * @param int $step {@see self::STEP_UP} or {@see self::STEP_DOWN}
     * @param bool $readStoredStock Read storage for an increase that may precede stock synchronization
     * @return int Quantity of the line from now on
     * @throws InvalidArgumentException When the step is neither one unit up nor one down, or a loaded stock object does
     *     not match its collection
     * @throws ValidationException When the product does not exist, is a unique piece or is not in the cart, the step
     *     down would leave no unit, or the step up would go above the stock
     * @throws LogicException When the stock collection class constants are not configured
     * @throws DatabaseException When the stock cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched stock item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched stock item is rejected by a non-manual collection
     * @throws HilosException When the products cannot be read
     */
    public static function stepped(int $productId, ?int $quantity, int $step, bool $readStoredStock = false): int
    {
        if ($step !== self::STEP_UP && $step !== self::STEP_DOWN) {
            throw new InvalidArgumentException("A cart line steps by one unit up or down, not by {$step}");
        }
        $current = self::currentQuantity($productId, $quantity);
        if ($step === self::STEP_DOWN) {
            return self::keptQuantity($current + $step);
        }

        $stepped = $current + $step;
        self::checkInStock($productId, $stepped, $readStoredStock);

        return $stepped;
    }

    /**
     * Returns the quantity of a stock item's line set to an exact number.
     *
     * The number the line already holds changes nothing, and is not checked against the stock.
     *
     * @param int $productId Id of the product
     * @param ?int $quantity Quantity of its line now, or null when the cart has none
     * @param int $wanted Number of units the line is to hold
     * @param bool $readStoredStock Read storage independently of the worker's stock mirror
     * @return int Quantity of the line from now on
     * @throws ValidationException When the product does not exist, is a unique piece or is not in the cart, the number
     *     is below one unit, or it is above the stock
     * @throws LogicException When the stock collection class constants are not configured
     * @throws InvalidArgumentException When a loaded stock object does not match its collection
     * @throws DatabaseException When the stock cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched stock item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched stock item is rejected by a non-manual collection
     * @throws HilosException When the products cannot be read
     */
    public static function exact(int $productId, ?int $quantity, int $wanted, bool $readStoredStock = false): int
    {
        $current = self::currentQuantity($productId, $quantity);
        $kept = self::keptQuantity($wanted);
        if ($kept === $current) {
            return $current;
        }
        self::checkInStock($productId, $kept, $readStoredStock);

        return $kept;
    }

    /**
     * Checks the edit's starting quantity before applying the exact quantity rules against stored stock.
     *
     * @param int $productId Id of the product
     * @param int $currentQuantity Saved quantity of the addressed line
     * @param int $expectedQuantity Saved quantity the edit started from
     * @param string $quantity Original quantity text from the form
     * @return int Quantity of the line from now on
     * @throws ValidationException When the edit is stale, the input is invalid or the product or stock refuses the change
     * @throws LogicException When the stock collection class constants are not configured
     * @throws InvalidArgumentException When a loaded stock object does not match its collection
     * @throws DatabaseException When the stock cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched stock item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched stock item is rejected by a non-manual collection
     * @throws HilosException When the products cannot be read
     */
    public static function exactFrom(int $productId, int $currentQuantity, int $expectedQuantity, string $quantity): int
    {
        if ($currentQuantity !== $expectedQuantity) {
            throw new ValidationException('The cart quantity changed. Review it and try again.');
        }

        return self::exact($productId, $currentQuantity, self::parseQuantity($quantity), readStoredStock: true);
    }

    /**
     * Sums the units of a product over every warehouse that keeps it.
     *
     * The holder of the stock answers from memory; any other process finds the product's rows by
     * one SQL query, and a row it already holds is read as the last sync left it.
     *
     * @param int $productId Id of the product
     * @return int Units in stock, zero when no warehouse keeps it
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match the collection
     * @throws DatabaseException When the rows cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    public static function unitsInStock(int $productId): int
    {
        $units = 0;
        foreach (Hilos::$db->productWarehouseStocks->whereColumnIs(ObjectProductWarehouseStock::productId, $productId) as $stock) {
            $units += $stock->units;
        }

        return $units;
    }

    /**
     * Checks that a line which steps or is set holds a stock item that is in the cart, and returns its quantity.
     *
     * @param int $productId Id of the product
     * @param ?int $quantity Quantity of its line now, or null when the cart has none
     * @return int Quantity of the line now
     * @throws ValidationException When the product does not exist, is a unique piece, or is not in the cart
     * @throws HilosException When the products cannot be read
     */
    private static function currentQuantity(int $productId, ?int $quantity): int
    {
        if (self::productKind($productId) === ProductKind::UNIQUE) {
            throw new ValidationException('A unique piece has no quantity: it is one of a kind');
        }
        if ($quantity === null) {
            throw new ValidationException('This product is not in the cart');
        }

        return $quantity;
    }

    /**
     * Checks that a line keeps one unit at least, and returns its quantity.
     *
     * @param int $quantity Quantity the line is to hold
     * @return int The same quantity
     * @throws ValidationException When it is below one unit
     */
    private static function keptQuantity(int $quantity): int
    {
        if ($quantity < self::QUANTITY_MIN) {
            throw new ValidationException(self::QUANTITY_BELOW_MINIMUM);
        }

        return $quantity;
    }

    /**
     * Parses a decimal integer and checks the column limit before casting.
     *
     * @param string $quantity Original quantity text from the form
     * @return int Quantity within the storage range
     * @throws ValidationException When the text is malformed or the quantity lies outside the cart range
     */
    private static function parseQuantity(string $quantity): int
    {
        $quantity = trim($quantity);
        if (preg_match('/\A-?[0-9]+\z/', $quantity) !== 1) {
            throw new ValidationException('Enter a whole number of units.');
        }
        if ($quantity[0] === '-') {
            throw new ValidationException(self::QUANTITY_BELOW_MINIMUM);
        }

        $digits = ltrim($quantity, '0');
        $limit = (string) self::QUANTITY_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new ValidationException('That quantity is too large.');
        }

        return self::keptQuantity((int) $digits);
    }

    /**
     * Checks that the stock of a stock item covers a quantity a person asks for.
     *
     * @param int $productId Id of the stock item
     * @param int $quantity Quantity the line is to hold
     * @param bool $readStoredStock Read storage independently of the worker's stock mirror
     * @throws ValidationException When the product is out of stock, or the quantity is above its stock
     * @throws LogicException When the stock collection class constants are not configured
     * @throws InvalidArgumentException When a loaded stock object does not match its collection
     * @throws DatabaseException When the stock cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched stock item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched stock item is rejected by a non-manual collection
     */
    private static function checkInStock(int $productId, int $quantity, bool $readStoredStock = false): void
    {
        $units = $readStoredStock
            ? Hilos::$db->productWarehouseStocks->storedUnitsOfProduct($productId)
            : self::unitsInStock($productId);
        if ($units === 0) {
            throw new ValidationException('This product is out of stock');
        }
        if ($quantity > $units) {
            throw new ValidationException("Only {$units} in stock");
        }
    }

    /**
     * Checks that a product exists and returns its kind; products are read by key.
     *
     * @param int $productId Id of the product
     * @return ProductKind Kind of the product
     * @throws ValidationException When there is no such product
     * @throws HilosException When the products cannot be read
     */
    private static function productKind(int $productId): ProductKind
    {
        if (!isset(Hilos::$db->products[$productId])) {
            throw new ValidationException('This product does not exist');
        }

        return Hilos::$db->products[$productId]->kind;
    }
}
