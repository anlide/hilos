<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Domain\Event\ChatEventInterface;
use Demo\WebSocketTest\Domain\Event\Events\ChatClearedEvent;
use Demo\WebSocketTest\Domain\Event\Events\ChatCreatedEvent;
use Demo\WebSocketTest\Domain\Event\Events\MessageSentEvent;
use Demo\WebSocketTest\Domain\Event\Events\UserJoinedEvent;
use Demo\WebSocketTest\Domain\Event\Events\UserLeftEvent;
use Demo\WebSocketTest\Domain\Event\Events\UserRenamedEvent;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use Hilos\Logging\Logger\Logger;

/**
 * ChatAgent - Monopolistic agent for chat management
 *
 * Runs in monopolistic worker process. Manages chat state and history.
 * State stored only in memory (no persistence).
 */
class ChatAgent extends AbstractAgent
{
    /** @var array<ChatEventInterface> Chat event history */
    private array $history = [];

    /**
     * Get agent type
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return AgentType::CHAT;
    }

    /**
     * Get agent index
     *
     * Chat agent has no index (global singleton)
     *
     * @return ?string Agent index (null for global chat agent)
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Called when agent is started
     */
    public function onStart(): void
    {
        // Add chat created event to history
        $this->addEvent(new ChatCreatedEvent());
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
    }

    /**
     * Agent-specific tick implementation
     */
    public function onTick(): void
    {
        // No periodic tasks needed - cleanup handled via cron signals
    }

    /**
     * Add event to history
     *
     * @param ChatEventInterface $event Event object
     */
    private function addEvent(ChatEventInterface $event): void
    {
        $this->history[] = $event;

        // Broadcast event to all connected clients via daemon
        // TODO: Use proper DTO for broadcasting
        // For now, this will be implemented when agent manager is ready
    }

    /**
     * Cleanup chat history
     */
    private function cleanupHistory(): void
    {
        $this->history = [];
        $this->addEvent(new ChatClearedEvent());
    }

    /**
     * Handle page subscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalPageSubscribe(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        $clientId = $dataArray['clientId'] ?? '';
        $page = $dataArray['page'] ?? null;
        $groups = $dataArray['groups'] ?? [];
        Logger::logAgentInfo($this->getId(), "Page subscribe signal received: source={$source}, name={$name}, client={$clientId}, page=" . ($page ?? 'null') . ", groups=" . json_encode($groups));

        // Add user joined event if subscribing to chat page
        if (($page === 'chat' || $page === null) && $clientId !== '') {
            $this->addEvent(new UserJoinedEvent($clientId));
        }
    }

    /**
     * Handle page unsubscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalPageUnsubscribe(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        $clientId = $dataArray['clientId'] ?? '';
        $page = $dataArray['page'] ?? false;
        $groups = $dataArray['groups'] ?? [];
        Logger::logAgentInfo($this->getId(), "Page unsubscribe signal received: source={$source}, name={$name}, client={$clientId}, page=" . ($page ? 'true' : 'false') . ", groups=" . json_encode($groups));

        // Add user left event if unsubscribing from chat page
        if (($page === 'chat' || $page === false) && $clientId !== '') {
            $this->addEvent(new UserLeftEvent($clientId));
        }
    }

    /**
     * Handle action signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalAction(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        $clientId = $dataArray['clientId'] ?? '';
        $action = $dataArray['action'] ?? '';
        $actionData = $dataArray['data'] ?? [];
        Logger::logAgentInfo($this->getId(), "Action signal received: source={$source}, name={$name}, client={$clientId}, action={$action}, data=" . json_encode($actionData));

        // Handle different action types
        switch ($action) {
            case 'rename':
                // User renamed themselves
                $oldName = $actionData['oldName'] ?? '';
                $newName = $actionData['newName'] ?? '';
                if ($oldName !== '' && $newName !== '' && $clientId !== '') {
                    $this->addEvent(new UserRenamedEvent($clientId, $oldName, $newName));
                }
                break;

            case 'message':
                // User sent a message
                $message = $actionData['message'] ?? '';
                if ($message !== '' && $clientId !== '') {
                    $this->addEvent(new MessageSentEvent($clientId, $message));
                }
                break;
        }
    }

    /**
     * Handle cron signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalCron(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        Logger::logAgentInfo($this->getId(), "Cron signal received: source={$source}, name={$name}, data=" . json_encode($dataArray));

        // Handle cleanup cron task
        if ($name === 'cleanup_history') {
            $this->cleanupHistory();
        }
    }
}
