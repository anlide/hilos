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
 * Monopolistic library of the shop's products - the one writer of the products and their addresses.
 *
 * Creating and editing a product happen here and nowhere else, and so does every address a
 * product's name gives it: whatever writes a product - a page subscribed to the library
 * included - writes in the library's own process, under its claim. One instance runs per
 * cluster, and the placement policy chooses its node. The catalog grows, so the library alone
 * holds the products and their addresses whole; everybody else reads one by key or queries the
 * database (docs/agents/architecture/entity-libraries.md).
 */
final class ProductsLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The products and their addresses, both owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::products => TruthSourceOperation::BY_KIND,
        EcommerceShopDbContext::productSlugs => TruthSourceOperation::BY_KIND,
    ];

    /**
     * @var list<string> The categories a product is put in: the categories library owns them,
     *     and a product's write only checks that its category still exists; a category deleted after
     *     that check is refused by the database's reference to it
     */
    public const array READS_DB = [
        EcommerceShopDbContext::categories,
    ];

    public const string AGENT_TYPE = AgentType::PRODUCTS_LIBRARY;

    /** Product actions arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Declares the products and their addresses complete in this process.
     *
     * Both load lazily by key everywhere, and a product's write walks them whole - the address
     * of a name and the picture are checked against every product and every address it has
     * had - so the holder of the set reads them in once here. The declaration outlives a
     * db-reset and a restore: a collection that was whole reads itself whole again
     * ({@see Objects::reHydrate()}).
     *
     * @throws LogicException When either collection is not mounted, or its entity collection class is not configured
     * @throws DatabaseException When loading either collection from the database fails
     */
    public function onStart(): void
    {
        (Hilos::$db->products->getObjectCollection() ?? throw new LogicException('The products collection is not mounted'))
            ->preloadAll();
        (Hilos::$db->productSlugs->getObjectCollection() ?? throw new LogicException('The product addresses collection is not mounted'))
            ->preloadAll();
    }

    /**
     * Products are durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }
}
