<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\ModerationBotRequestSignalData;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
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

    /** @var bool Whether first tick has already sent a message (one message per agent lifecycle for verification) */
    private bool $hasSentMessage = false;

    /** @var list<string> Random messages for verification flow (no LLM) */
    private const array RANDOM_MESSAGES = [
        'Hello everyone!',
        "How's it going?",
        'Nice to meet you all.',
        'What a lovely chat we have here.',
        'Greetings from the bot!',
    ];

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
        $botId = (int) $this->agentIndex;
        $this->sendToAgent(ChatSignalConstants::BOT_JOINED, new BotAgentSignalData(botId: $botId));
    }

    /**
     * Called when agent is stopped
     *
     * No BOT_AGENT_STOP signal - agent discovers state change through synchronized data.
     */
    public function onStop(): void
    {
        $botId = (int) $this->agentIndex;
        $this->sendToAgent(ChatSignalConstants::BOT_LEFT, new BotAgentSignalData(botId: $botId));
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
     * Handle RT sync created signal.
     *
     * @param RtSyncCreatedSignalData $data Signal data
     * @param string $source Source of the signal
     * @param string $name Name of the signal
     */
    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === RtChatContext::chatContexts) {
            Logger::logAgentInfo(
                $this->getId(),
                "[rt_sync] ChatContext created: stateId={$data->stateId}, row=" . json_encode($data->row, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * Handle RT sync updated signal.
     *
     * @param RtSyncUpdatedSignalData $data Signal data
     * @param string $source Source of the signal
     * @param string $name Name of the signal
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        if ($data->collectionKey === RtChatContext::chatContexts) {
            Logger::logAgentInfo(
                $this->getId(),
                "[rt_sync] ChatContext updated: stateId={$data->stateId}, diff=" . json_encode($data->row, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * Agent-specific tick implementation
     *
     * Sends one random message on first tick to verify full flow: moderation → event → frontend.
     */
    public function onTick(): void
    {
        if ($this->hasSentMessage) {
            return;
        }
        $this->hasSentMessage = true;

        $botId = (int) $this->agentIndex;
        $message = self::RANDOM_MESSAGES[array_rand(self::RANDOM_MESSAGES)];

        $this->sendToAgent(
            ChatSignalConstants::MODERATE_BOT_REQUEST,
            new ModerationBotRequestSignalData(botId: $botId, message: $message),
        );
    }
}
