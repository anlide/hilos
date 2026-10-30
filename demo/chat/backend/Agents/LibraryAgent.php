<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Database\ChatDbContext;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;

/**
 * Monopolistic library worker for admin-managed chat catalog entities.
 *
 * Owns bot profiles.
 */
final class LibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The bot catalog its admin CRUD pages write
     */
    public const array OWNS_DB = [
        ChatDbContext::bots => TruthSourceOperation::BY_KIND,
    ];

    public const string AGENT_TYPE = AgentType::LIBRARY;

    /**
     * Library data is durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }
}
