<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;

/**
 * Monopolistic library of the shop's warehouses - their one writer.
 *
 * Creating, editing and deleting a warehouse happen here and nowhere else: whatever writes a
 * warehouse writes in the library's own process, under its claim. One instance runs
 * per cluster, and the placement policy chooses its node (docs/agents/architecture/entity-libraries.md).
 */
final class WarehousesLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The warehouses table, owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::warehouses => TruthSourceOperation::BY_KIND,
    ];

    public const string AGENT_TYPE = AgentType::WAREHOUSES_LIBRARY;

    /** Warehouse actions arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Warehouses are durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }
}
