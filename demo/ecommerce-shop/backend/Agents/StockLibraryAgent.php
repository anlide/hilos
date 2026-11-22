<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * Monopolistic library of the shop's stock - the one writer of the units each warehouse keeps of each product.
 *
 * Setting the units of a stock item and putting a unique piece into a warehouse happen here and
 * nowhere else: whatever writes the stock writes in the library's own process, under its claim.
 * One instance runs per cluster, and the placement policy chooses its node. The library alone
 * holds the stock whole; everybody else reads one row by key or queries the database
 * (docs/agents/architecture/entity-libraries.md). The stock has a library of its own, not the
 * products library's, because it is the number that changes most - orders will take from it -
 * and the products library does not become the writer of those changes.
 */
final class StockLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The stock, owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::productWarehouseStocks => TruthSourceOperation::BY_KIND,
    ];

    /**
     * @var list<string> The products and the warehouses the stock is kept of and in: their own libraries
     *     write them, and a stock write only checks the kind of its product and that its warehouse still exists;
     *     a warehouse deleted after that check is refused by the database's reference to it
     */
    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::warehouses,
    ];

    public const string AGENT_TYPE = AgentType::STOCK_LIBRARY;

    /** Stock actions arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Declares the stock complete in this process.
     *
     * The stock loads lazily by key everywhere, and a stock write walks the rows of its product,
     * so the holder of the set reads it in once here. The declaration outlives a db-reset and a
     * restore: a collection that was whole reads itself whole again ({@see Objects::reHydrate()}).
     *
     * @throws LogicException When the collection is not mounted, or its entity collection class is not configured
     * @throws DatabaseException When loading the collection from the database fails
     */
    public function onStart(): void
    {
        (Hilos::$db->productWarehouseStocks->getObjectCollection() ?? throw new LogicException('The stock collection is not mounted'))
            ->preloadAll();
    }

    /**
     * The stock is durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }
}
