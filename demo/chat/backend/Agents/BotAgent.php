<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Utils\Logger;

/**
 * BotAgent - Regular agent for bot management
 *
 * Runs in regular worker process. One agent per bot (agentIndex = bot.id).
 * Manages bot interactions and chat behavior.
 */
class BotAgent extends AbstractAgent
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::BOT;

    /** @var string Agent index (bot id) */
    private string $agentIndex;

    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new \RuntimeException('BotAgent requires non-empty agentIndex (bot id)');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * Get agent type
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index
     *
     * Bot agent index is the bot id (string)
     *
     * @return ?string Agent index
     */
    public function getIndex(): ?string
    {
        return $this->agentIndex;
    }

    /**
     * Called when agent is started
     */
    public function onStart(): void
    {
        Logger::logAgentStart($this->getId(), $this->getType());
    }

    /**
     * Called when agent is stopped
     *
     * No BOT_AGENT_STOP signal - agent discovers state change through synchronized data.
     */
    public function onStop(): void
    {
        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Handle DB sync updated signal for the bot
     *
     * If the bot is marked as inactive, stop the agent.
     *
     * @param DbSyncUpdatedSignalData $data Signal data containing updated row
     * @param string $source Source of the signal
     * @param string $name Name of the signal
     */
    public function onSignalDbSyncUpdated(DbSyncUpdatedSignalData $data, string $source, string $name): void
    {
        $botId = (int) $this->agentIndex;
        $signalBotId = (int) $data->idString;
        if ($signalBotId !== $botId) {
            return;
        }
        $active = $data->row[ObjectBot::active] ?? null;
        if ($active === false) {
            $this->selfStop();
        }
    }

    /**
     * Handle DB sync deleted signal for the bot
     *
     * If the bot is deleted, stop the agent.
     *
     * @param DbSyncDeletedSignalData $data Signal data containing deleted row info
     * @param string $source Source of the signal
     * @param string $name Name of the signal
     */
    public function onSignalDbSyncDeleted(DbSyncDeletedSignalData $data, string $source, string $name): void
    {
        $botId = (int) $this->agentIndex;
        $signalBotId = (int) $data->idString;
        if ($signalBotId !== $botId) {
            return;
        }
        $this->selfStop();
    }

    /**
     * Agent-specific tick implementation
     */
    public function onTick(): void
    {
        // TODO: Add bot-specific logic here
        // For example: process queued bot messages, handle bot responses, etc.
    }
}
