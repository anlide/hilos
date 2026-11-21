<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\CLI\DTO\WarehouseDeleteCommandData;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Warehouse as EntityWarehouse;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Environment\NonProductionGate;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * Monopolistic library of the shop's warehouses - their one writer.
 *
 * Creating, editing and deleting a warehouse happen here and nowhere else: whatever writes a
 * warehouse writes in the library's own process, under its claim. One instance runs
 * per cluster, and the placement policy chooses its node (docs/agents/architecture/entity-libraries.md).
 *
 * It also answers the test-only warehouse delete command, by which an e2e removes the warehouse it added.
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

    public const array AGENT_COMMANDS = [
        EcommerceShopCommandConstants::WAREHOUSE_TEST_DELETE => WarehouseDeleteCommandData::class,
    ];

    /** Warehouse actions and commands arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Answers the test-only warehouse delete command.
     *
     * @param CommandRequestDTO $data Topology-hydrated command
     * @param string $source Command source, unused
     * @param string $name Signal name, unused
     * @throws HilosException When warehouses cannot be read or deleted, or the reply cannot be queued
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case EcommerceShopCommandConstants::WAREHOUSE_TEST_DELETE:
                if (!NonProductionGate::admitted()) {
                    $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Warehouse delete is test-only'));

                    return;
                }
                $this->deleteWarehouse($data, $data->parsedPayload);

                return;

            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
        }
    }

    /**
     * Warehouses are durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }

    /**
     * Removes the warehouse of the given name; a name no warehouse carries is answered too, since cleanup
     * also runs after an add that never arrived.
     *
     * @param CommandRequestDTO $request Command to answer
     * @param WarehouseDeleteCommandData $query Name of the warehouse to remove
     * @throws HilosException When warehouses cannot be read or deleted, or the reply cannot be queued
     */
    private function deleteWarehouse(CommandRequestDTO $request, WarehouseDeleteCommandData $query): void
    {
        $warehouse = Hilos::$db->warehouses->whereColumnIs(EntityWarehouse::name, $query->name)->first();
        if ($warehouse !== null) {
            $warehouse->actions->delete();
        }
        $this->replyToCommand(CommandReplyDTO::ok($request->correlationId, [EcommerceShopCommandConstants::deleted => $warehouse !== null]));
    }
}
