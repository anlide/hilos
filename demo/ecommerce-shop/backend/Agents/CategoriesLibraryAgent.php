<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;

/**
 * Monopolistic library of the shop's categories - the one writer of the categories and their addresses.
 *
 * Creating, editing, moving and deleting a category happen here and nowhere else, and so does every
 * address a category's name gives it: whatever writes a category writes in the library's own process,
 * under its claim. One instance runs per cluster, and the placement policy chooses its node
 * (docs/agents/architecture/entity-libraries.md).
 */
final class CategoriesLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The categories and their addresses, both owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::categories => TruthSourceOperation::BY_KIND,
        EcommerceShopDbContext::categorySlugs => TruthSourceOperation::BY_KIND,
    ];

    public const string AGENT_TYPE = AgentType::CATEGORIES_LIBRARY;

    /** Category actions arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Categories are durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }
}
