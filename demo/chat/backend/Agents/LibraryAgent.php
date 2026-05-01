<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Database\DbChatContext;
use Hilos\Core\Agent\AbstractAgent;

/**
 * Monopolistic library worker for admin-managed chat catalog entities.
 *
 * Owns bot profiles and moderator prompt pieces.
 */
final class LibraryAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::LIBRARY;

    /**
     * Registers library DB truth sources for admin CRUD pages.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(DbChatContext::bots);
        $this->registerDbTruthSource(DbChatContext::moderatorPromptPieces);
    }

    /**
     * Library data is durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }
}
