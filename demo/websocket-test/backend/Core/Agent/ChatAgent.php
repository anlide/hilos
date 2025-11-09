<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Utils\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Utils\DTO\Agent\AgentMessageDTOInterface;
use Hilos\Utils\DTO\Agent\MessageFromDaemonDTO;
use Hilos\Utils\DTO\Agent\MessageFromUserDTO;
use Hilos\Utils\DTO\BaseDTO;
use Hilos\Logging\Logger\Logger;

/**
 * ChatAgent - Monopolistic agent for chat management
 *
 * Runs in monopolistic worker process. Manages chat state and history.
 * State stored only in memory (no persistence).
 */
class ChatAgent extends AbstractAgent
{
    /** @var array Chat event history */
    private array $history = [];

    /** @var float Last cleanup timestamp */
    private float $lastCleanup = 0.0;

    /** @var float Cleanup interval in milliseconds (5 minutes) */
    private const float CLEANUP_INTERVAL = 300000.0; // 5 minutes

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
        // Add chat started event to history
        $this->addEvent('chat_started', []);
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
    protected function doTick(): void
    {
        $currentTime = microtime(true) * 1000;

        // Check if cleanup is needed (every 5 minutes)
        if (($currentTime - $this->lastCleanup) >= self::CLEANUP_INTERVAL) {
            $this->cleanupHistory();
            $this->lastCleanup = $currentTime;
        }
    }

    /**
     * Handle message from daemon
     *
     * @param MessageFromDaemonDTO $message Message from daemon
     * @return AgentMessageDTOInterface|null Response DTO
     */
    public function handleMessageFromDaemon(MessageFromDaemonDTO $message): ?AgentMessageDTOInterface
    {
        $action = $message->action;

        switch ($action) {
            case 'get_history':
                // TODO: Return proper DTO
                // For now return null - will be implemented with proper DTO
                return null;

            case 'add_event':
                $eventType = $message->payload['eventType'] ?? '';
                $eventData = $message->payload['eventData'] ?? [];
                $this->addEvent($eventType, $eventData);
                return null;

            default:
                return null;
        }
    }

    /**
     * Handle message from user
     *
     * @param MessageFromUserDTO $message Message from user
     * @return AgentMessageDTOInterface|null Response DTO
     */
    public function handleMessageFromUser(MessageFromUserDTO $message): ?AgentMessageDTOInterface
    {
        // payload is array in MessageFromUserDTO, convert to string for logging
        $payloadStr = json_encode($message->payload, JSON_UNESCAPED_UNICODE);
        Logger::logAgentUserMessage($this->getId(), $message->userId, $payloadStr);
        // Chat agent can receive messages from users
        // TODO: Implement proper message handling with DTO
        return null;
    }

    /**
     * Add event to history
     *
     * @param string $eventType Event type
     * @param array $eventData Event data
     */
    private function addEvent(string $eventType, array $eventData): void
    {
        $event = [
            'type' => $eventType,
            'timestamp' => time(),
            'data' => $eventData,
        ];

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
        $this->addEvent('history_cleared', []);
    }

    /**
     * Handle system signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalSystem(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        Logger::logAgentInfo($this->getId(), "System signal received: source={$source}, name={$name}, data=" . json_encode($dataArray));
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
    }

    /**
     * Handle page update subscription signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalPageUpdateSubscription(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        Logger::logAgentInfo($this->getId(), "Page update subscription signal received: source={$source}, name={$name}, data=" . json_encode($dataArray));
    }

    /**
     * Handle group subscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupSubscribe(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        Logger::logAgentInfo($this->getId(), "Group subscribe signal received: source={$source}, name={$name}, data=" . json_encode($dataArray));
    }

    /**
     * Handle group unsubscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupUnsubscribe(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        Logger::logAgentInfo($this->getId(), "Group unsubscribe signal received: source={$source}, name={$name}, data=" . json_encode($dataArray));
    }

    /**
     * Handle group update subscription signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupUpdateSubscription(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        Logger::logAgentInfo($this->getId(), "Group update subscription signal received: source={$source}, name={$name}, data=" . json_encode($dataArray));
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
    }
}
