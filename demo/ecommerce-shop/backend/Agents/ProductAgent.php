<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductSlug as EntityProductSlug;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\ShopFront\ProductReading;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * Agent of one address of the storefront's product page (`/product/{slug}`).
 *
 * Indexed by the address: it comes up with the first visitor of that address and goes to sleep
 * after the last one has left and the idle window has passed. Any non-empty address is one - an
 * address may name no product at all, and the agent of an address a product had before a rename
 * reads that product under its new name. The agent reads the product and its stock, which the
 * products and stock libraries own, and writes nothing; it holds nothing between calls but its
 * address, so every reading asks the collections anew.
 */
final class ProductAgent extends AbstractAgent
{
    /**
     * @var list<string> The products and their addresses (the products library owns them) and the
     *     stock (the stock library owns it): read by the address, never written
     */
    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productSlugs,
        EcommerceShopDbContext::productWarehouseStocks,
    ];

    public const string AGENT_TYPE = AgentType::PRODUCT;

    /** A product page address is read on request; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /** @var string The page address this agent answers for */
    private readonly string $address;

    /**
     * @param string $agentIndex The page address; its form is not checked, an address may name nothing
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('ProductAgent requires non-empty agentIndex (product page address)');
        }
        $this->agentIndex = $agentIndex;
        $this->address = $agentIndex;
    }

    /**
     * @return string The page address this agent answers for
     */
    public function address(): string
    {
        return $this->address;
    }

    /**
     * Reads the product the address names and its stock, anew on every call.
     *
     * The product whose current address it is comes first; otherwise the product that had it
     * before a rename. The returned product's slug tells the two apart ({@see ProductReading}).
     *
     * @return ?ProductReading The product and its units over every warehouse, or null when the address names no product
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a column names no field of its entity, or a loaded object does not match
     * @throws DatabaseException When the rows cannot be read
     * @throws ItemNotFoundException When a former address leads to a product that no longer exists
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws HilosException When the database context or an item getter refuses the read
     */
    public function reading(): ?ProductReading
    {
        $product = Hilos::$db->products->whereColumnIs(EntityProduct::slug, $this->address)->first();
        if ($product === null) {
            $formerAddress = Hilos::$db->productSlugs->whereColumnIs(EntityProductSlug::slug, $this->address)->first();
            if ($formerAddress === null) {
                return null;
            }
            $product = Hilos::$db->products[$formerAddress->productId];
        }

        $units = 0;
        foreach (Hilos::$db->productWarehouseStocks->whereColumnIs(EntityProductWarehouseStock::product_id, $product->id) as $stock) {
            $units += $stock->units;
        }

        return new ProductReading($product, $units);
    }

    /**
     * The agent writes nothing and holds nothing, so stop cleanup only unregisters it via WorkerManager.
     */
    public function onStop(): void
    {
    }
}
