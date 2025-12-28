<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\DTO\ChatEventSignalData;
use Demo\WebSocketTest\DTO\SubscriptionResponseSignalData;
use Demo\WebSocketTest\DTO\WebSocketHandshakeSignalDTO;
use Demo\WebSocketTest\Domain\Event\ChatEventInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Demo\WebSocketTest\Domain\Event\Events\ChatClearedEvent;
use Demo\WebSocketTest\Domain\Event\Events\ChatCreatedEvent;
use Demo\WebSocketTest\Domain\Event\Events\MessageSentEvent;
use Demo\WebSocketTest\Domain\Event\Events\UserJoinedEvent;
use Demo\WebSocketTest\Domain\Event\Events\UserLeftEvent;
use Demo\WebSocketTest\Domain\Event\Events\UserRenamedEvent;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
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
    /** @var array<ChatEventInterface> Chat event history */
    private array $history = [];

    /** @var ?float Agent start time (microtime) */
    private ?float $startTime = null;

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
     */
    public function onStart(): void
    {
        // Store agent start time
        $this->startTime = microtime(true);

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
     * @param string|null $excludeClientId Client ID to exclude from receiving the event (optional)
     */
    private function addEvent(ChatEventInterface $event, ?string $excludeClientId = null): void
    {
        $this->history[] = $event;

        // Broadcast event to all connected clients via SignalRouter
        $eventData = new ChatEventSignalData($event);

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
        if ($page === 'main') {
            // Get user ID from Idea user object
            $userId = $user->id;
            
            // Exclude the joining user from receiving their own join event
            $this->addEvent(new UserJoinedEvent($userId), $clientId);

            // Send subscription response with all events, start time and user info
            $subscriptionData = new SubscriptionResponseSignalData(
                events: $this->history,
                startTime: $this->startTime,
                userId: $userId,
                username: $user->name,
                theme: $user->theme,
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
                    $this->addEvent(new UserRenamedEvent($clientId, $oldName, $newName));
                }
                break;

            case 'message':
                // User sent a message
                $message = $actionData['message'] ?? '';
                if ($message !== '') {
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
