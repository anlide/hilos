<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Worker;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Object\Event as ObjectEvent;
use Demo\WebSocketTest\DTO\ChatEventSignalData;
use Demo\WebSocketTest\DTO\SubscriptionResponseSignalData;
use Demo\WebSocketTest\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Database;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\DTO\BaseDTO;
use Hilos\Exception\DatabaseException;
use Hilos\Logging\Logger\Logger;
use RuntimeException;

/**
 * ChatAgent - Monopolistic agent for chat management
 *
 * Runs in monopolistic worker process. Manages chat state and history.
 * State stored only in memory (no persistence).
 */
class ChatAgent extends AbstractAgent
{
    /** @var ?float Agent start time (microtime) */
    private ?float $startTime = null;

    /** @var array<string, int> Mapping of clientId to userId */
    private array $clientIdToUserId = [];

    /**
     * Constructor
     *
     * @param SignalRouter $signalRouter Signal router instance
     */
    public function __construct(SignalRouter $signalRouter)
    {
        parent::__construct($signalRouter);
    }

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
   * @throws DatabaseException If database operation fails
   */
    public function onStart(): void
    {
        // Store agent start time
        $this->startTime = microtime(true);

        // Register this agent as truth source for event table (all keys)
        TruthSourceRegistry::register('event', true, $this->getId());

        // Add chat created event to history (system event with userId = null)
        $this->addEvent(ChatEventType::CHAT_CREATED);
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        // Unregister as truth source
        TruthSourceRegistry::unregister('event', $this->getId());
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
   * @param ChatEventType $type Event type
   * @param ?int $userId User ID (null for system events or to determine from clientId)
   * @param ?array $data Event-specific data (optional, may contain clientId for userId resolution)
   * @param string|null $excludeClientId Client ID to exclude from receiving the event (optional)
   * @throws RuntimeException If userId cannot be determined when needed
   * @throws DatabaseException If database operation fails
   */
    private function addEvent(ChatEventType $type, ?int $userId = null, ?array $data = null, ?string $excludeClientId = null): void
    {
        // Determine userId from data if not provided
        if ($userId === null && $data !== null && isset($data['clientId'])) {
            $clientId = $data['clientId'];
            if (isset($this->clientIdToUserId[$clientId])) {
                $userId = $this->clientIdToUserId[$clientId];
            }
        }

        // Add event to collection (saves to database and adds to collection)
        $objectEvent = Idea::$storage->events->add($type->value, $userId, $data);

        // Broadcast event to all connected clients via SignalRouter
        $eventData = new ChatEventSignalData($objectEvent);

        // Wrap event data in WebSocketSignalData for WebSocket routing
        $signalData = new WebSocketSignalData(
            data: $eventData,
            targetClientId: null,
            targetGroup: null,
            excludeClientId: $excludeClientId,
        );

        // Determine signal type based on event
        // If event has clientId, we might want to send only to that user or to all
        // For now, send to all users
        $signalType = new SignalType(SignalTypeConstants::WS_ALL);
        $signalName = new SignalName('new_event');

        // Queue signal in SignalRouter
        $this->signalRouter->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: $signalType,
            signalName: $signalName,
            signalData: $signalData,
        );
    }

  /**
   * Cleanup chat history
   * @throws DatabaseException If database operation fails
   */
    private function cleanupHistory(): void
    {
        Idea::$storage->clearEvents();

        // Add ChatClearedEvent as a system event (userId = null for system events)
        $this->addEvent(ChatEventType::CHAT_CLEARED);
    }

    /**
     * Handle page subscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     * @throws RuntimeException If data is not WebSocketHandshakeSignalDTO or session token is invalid
     * @throws DatabaseException If user registration fails
     */
    public function onSignalPageSubscribe(string $source, string $name, SignalDataInterface $data): void
    {
        // Validate that data is WebSocketHandshakeSignalDTO
        if (!($data instanceof WebSocketHandshakeSignalDTO)) {
            $dataType = get_class($data);
            Logger::logAgentError($this->getId(), "Invalid signal data type: expected WebSocketHandshakeSignalDTO, got {$dataType}");
            throw new RuntimeException("Expected WebSocketHandshakeSignalDTO, got {$dataType}");
        }

        // Now we can safely use WebSocketHandshakeSignalDTO type
        $handshakeData = $data;

        $dataArray = $handshakeData->toArray();
        $clientId = $dataArray['clientId'] ?? '';
        $page = $name;
        Logger::logAgentDebug($this->getId(), "Page subscribe signal received: source={$source}, client={$clientId}, page={$page}");

        if ($clientId === '') {
            return;
        }

        // Get session token from query parameters via DTO
        try {
            $sessionToken = $handshakeData->queryParams['X-Session-Token'] ?? null;

            // Validate that session token is provided and is a non-empty string
            if ($sessionToken === null || $sessionToken === '') {
                Logger::logAgentError($this->getId(), "X-Session-Token is required but not provided or empty");
                throw new RuntimeException("X-Session-Token is required but not provided or empty");
            }

            if (!is_string($sessionToken)) {
                $tokenType = gettype($sessionToken);
                Logger::logAgentError($this->getId(), "X-Session-Token must be a string, got: {$tokenType}");
                throw new RuntimeException("X-Session-Token must be a string, got: {$tokenType}");
            }

            Logger::logAgentDebug($this->getId(), "Session token from DTO: " . $sessionToken);

            // Try to find existing user
            $user = Idea::$idea->users->findBySession($sessionToken);

            // If user not found, register new user
            if ($user === null) {
                $user = Idea::$idea->users->register($sessionToken);
                Logger::logAgentDebug($this->getId(), "New user registered with session token: " . $sessionToken);
            } else {
                Logger::logAgentDebug($this->getId(), "Existing user found with session token: " . $sessionToken);
            }
        } catch (\Exception $e) {
            Logger::logAgentError($this->getId(), "Error processing session token: " . $e->getMessage());
            throw $e;
        }

        // Add user joined event if subscribing to chat page
        if ($page === PageConstants::MAIN) {
            // Get user ID from Idea user object
            $userId = $user->id;

            // Store mapping of clientId to userId
            $this->clientIdToUserId[$clientId] = $userId;

            // Exclude the joining user from receiving their own join event
            $this->addEvent(ChatEventType::USER_JOINED, $userId, null, $clientId);

            // Convert Idea events to array format for subscription response
            $eventsArray = [];
            foreach (Idea::$idea->events as $ideaEvent) {
                if ($ideaEvent->id === null) {
                    continue; // Skip events without ID
                }

                // Convert timestamp from database format to Unix timestamp
                $timestamp = strtotime($ideaEvent->timestamp);
                if ($timestamp === false) {
                    $timestamp = 0;
                }

                // Parse data from JSON string and merge userId if not already present
                $data = $ideaEvent->data === null ? [] : json_decode($ideaEvent->data, true) ?? [];
                if ($ideaEvent->userId !== null && !isset($data['userId'])) {
                    $data['userId'] = $ideaEvent->userId;
                }

                $eventsArray[] = [
                    'id' => $ideaEvent->id,
                    'type' => $ideaEvent->type,
                    'timestamp' => $timestamp,
                    'data' => $data,
                ];
            }

            // Send subscription response with all events, start time and user info
            $subscriptionData = new SubscriptionResponseSignalData(
                events: $eventsArray,
                startTime: $this->startTime,
                userId: $userId,
                username: $user->name,
            );

            // Wrap subscription data in WebSocketSignalData for WebSocket routing
            $signalData = new WebSocketSignalData(
                data: $subscriptionData,
                targetClientId: $clientId,
                targetGroup: null,
                excludeClientId: null,
            );

            $this->signalRouter->queueSignal(
                signalSource: $this->getAgentSignalSource(),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName('subscription_response'),
                signalData: $signalData,
            );
        }
    }

  /**
   * Handle page unsubscribe signal
   *
   * @param string $source Signal source
   * @param string $name Signal name
   * @param SignalDataInterface $data Signal data
   * @throws DatabaseException If database operation fails
   */
    public function onSignalPageUnsubscribe(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        $clientId = $dataArray['clientId'] ?? '';
        $page = $name;
        Logger::logAgentDebug($this->getId(), "Page unsubscribe signal received: source={$source}, client={$clientId}, page=$page");

        if ($clientId === '') {
            return;
        }

        // Add user left event if unsubscribing from chat page
        if ($page === 'main') {
            $this->addEvent(ChatEventType::USER_LEFT, null, ['clientId' => $clientId]);
            // Remove mapping when user leaves
            unset($this->clientIdToUserId[$clientId]);
        }
    }

  /**
   * Handle action signal
   *
   * @param string $source Signal source
   * @param string $name Signal name
   * @param SignalDataInterface $data Signal data
   * @throws DatabaseException If database operation fails
   */
    public function onSignalAction(string $source, string $name, SignalDataInterface $data): void
    {
        $dataArray = $data instanceof BaseDTO ? $data->toArray() : [];
        $clientId = $dataArray['clientId'] ?? '';
        $action = $dataArray['action'] ?? '';
        $actionData = $dataArray['data'] ?? [];
        Logger::logAgentInfo($this->getId(), "Action signal received: source={$source}, name={$name}, client={$clientId}, action={$action}, data=" . json_encode($actionData));

        if ($clientId === '') {
            return;
        }

        // Handle different action types
        switch ($action) {
            case 'rename':
                // User renamed themselves
                $oldName = $actionData['oldName'] ?? '';
                $newName = $actionData['newName'] ?? '';
                if ($oldName !== '' && $newName !== '') {
                    $this->addEvent(ChatEventType::USER_RENAMED, null, [
                        'clientId' => $clientId,
                        'oldName' => $oldName,
                        'newName' => $newName,
                    ]);
                }
                break;

            case 'message':
                // User sent a message
                $message = $actionData['message'] ?? '';
                if ($message !== '') {
                    $this->addEvent(ChatEventType::MESSAGE_SENT, null, [
                        'clientId' => $clientId,
                        'message' => $message,
                    ]);
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
   * @throws DatabaseException If database operation fails
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
