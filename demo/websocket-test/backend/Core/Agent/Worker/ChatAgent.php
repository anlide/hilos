<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Worker;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Constants\ChatCronConstants;
use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Constants\HttpHeaders;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
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
        // Register this agent as truth source for event and user tables (all keys)
        TruthSourceRegistry::register(Idea::getTableName(Idea::events), true, $this->getId());
        TruthSourceRegistry::register(Idea::getTableName(Idea::users), true, $this->getId());

        // Add chat created event to history (system event with userId = null)
        Idea::$idea->events->actions->add(ChatEventType::CHAT_CREATED->value);
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        TruthSourceRegistry::unregisterAgent($this->getId());
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
     * @param ?int $userId User ID (null for system events)
     * @param ?array $data Event-specific data (optional)
     * @param ?string $excludeClientId Client ID to exclude from receiving the event (optional)
     * @throws DatabaseException If database operation fails
     */
    private function addEvent(ChatEventType $type, ?int $userId = null, ?array $data = null, ?string $excludeClientId = null): void
    {
        // Add event to collection (saves to database and adds to collection)
        $event = Idea::$idea->events->actions->add($type->value, $userId, $data);

        // Broadcast event to all connected clients via SignalRouter
        $eventData = new ChatEventSignalData($event);

        // Wrap event data in WebSocketSignalData for WebSocket routing
        $this->signalRouter->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL),
            signalName: new SignalName('new_event'),
            signalData: new WebSocketSignalData(
                data: $eventData,
                targetClientId: null,
                targetGroup: null,
                excludeClientId: $excludeClientId,
            ),
        );
    }

    /**
     * Cleanup chat history
     * @throws DatabaseException If database operation fails
     */
    private function cleanupHistory(): void
    {
        Idea::$idea->events->actions->deleteAll();

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

        // Prepare variables
        $handshakeData = $data;
        $clientId = $handshakeData->clientId;
        $page = $name;
        Logger::logAgentDebug($this->getId(), "Page subscribe signal received: source={$source}, client={$clientId}, page={$page}");

        // Validate clientId
        if ($clientId === '') {
            throw new RuntimeException("Client ID is required but not provided or empty");
        }

        // Get or register user from session token
        $user = $this->getOrRegisterUser($handshakeData);

        // Handle page-specific subscription logic
        $this->handlePageSubscribe($page, $clientId, $user);
    }

    /**
     * Get or register user from session token
     * Returns user or throws exception
     *
     * @param WebSocketHandshakeSignalDTO $handshakeData Handshake data containing session token
     * @return IdeaUser User idea
     * @throws RuntimeException If session token is invalid
     * @throws DatabaseException If user registration fails
     */
    private function getOrRegisterUser(WebSocketHandshakeSignalDTO $handshakeData): IdeaUser
    {
        // Get session token from query parameters via DTO
        $sessionToken = $handshakeData->queryParams[HttpHeaders::SESSION_TOKEN] ?? null;

        // Validate that session token is provided and is a non-empty string
        if ($sessionToken === null || $sessionToken === '') {
            Logger::logAgentError($this->getId(), HttpHeaders::SESSION_TOKEN . " is required but not provided or empty");
            throw new RuntimeException(HttpHeaders::SESSION_TOKEN . " is required but not provided or empty");
        }

        if (!is_string($sessionToken)) {
            $tokenType = gettype($sessionToken);
            Logger::logAgentError($this->getId(), HttpHeaders::SESSION_TOKEN . " must be a string, got: {$tokenType}");
            throw new RuntimeException(HttpHeaders::SESSION_TOKEN . " must be a string, got: {$tokenType}");
        }

        Logger::logAgentDebug($this->getId(), "Session token from DTO: " . $sessionToken);

        // Try to find existing user
        $user = Idea::$idea->users->findBySession($sessionToken);

        // If user not found, register new user
        if ($user === null) {
            $user = Idea::$idea->users->actions->register($sessionToken);
            Logger::logAgentDebug($this->getId(), "New user registered with session token: " . $sessionToken);
        } else {
            Logger::logAgentDebug($this->getId(), "Existing user found with session token: " . $sessionToken);
        }

        return $user;
    }

    /**
     * Handle page-specific subscription logic
     * Routes to page-specific handler based on page name
     *
     * @param string $page Page name
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     * @throws DatabaseException If page handling fails
     */
    private function handlePageSubscribe(string $page, string $clientId, IdeaUser $user): void
    {
        switch ($page) {
            case PageConstants::MAIN:
                $this->handleMainPageSubscribe($clientId, $user);
                break;

            case PageConstants::PROFILE:
                $this->handleProfilePageSubscribe($clientId, $user);
                break;

            case PageConstants::USER:
                $this->handleUserPageSubscribe($clientId, $user);
                break;

            case PageConstants::BOT:
                $this->handleBotPageSubscribe($clientId, $user);
                break;

            case PageConstants::MODERATOR:
                $this->handleModeratorPageSubscribe($clientId, $user);
                break;

            case PageConstants::ADMIN:
                $this->handleAdminPageSubscribe($clientId, $user);
                break;

            case PageConstants::ADMIN_USERS:
                $this->handleAdminUsersPageSubscribe($clientId, $user);
                break;

            case PageConstants::ADMIN_MODERATOR:
                $this->handleAdminModeratorPageSubscribe($clientId, $user);
                break;

            case PageConstants::ADMIN_BOTS:
                $this->handleAdminBotsPageSubscribe($clientId, $user);
                break;

            default:
                // Unknown page - throw exception
                Logger::logAgentError($this->getId(), "Unknown page subscription: {$page}");
                throw new RuntimeException("Unknown page subscription: {$page}");
        }
    }

    /**
     * Handle main page subscription
     * Adds user joined event and sends subscription response with chat history
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     * @throws DatabaseException If operation fails
     */
    private function handleMainPageSubscribe(string $clientId, IdeaUser $user): void
    {
        // Exclude the joining user from receiving their own join event
        $this->addEvent(ChatEventType::USER_JOINED, $user->id, null, $clientId);

        // Send subscription response with all events and user info
        $subscriptionData = new SubscriptionResponseSignalData(
            events: Idea::$idea->events->toArray(idAsIndex: false),
            userId: $user->id,
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

    /**
     * Handle profile page subscription
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    private function handleProfilePageSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement profile page subscription logic
    }

    /**
     * Handle user page subscription
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    private function handleUserPageSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement user page subscription logic
    }

    /**
     * Handle bot page subscription
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    private function handleBotPageSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement bot page subscription logic
    }

    /**
     * Handle moderator page subscription
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    private function handleModeratorPageSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement moderator page subscription logic
    }

    /**
     * Handle admin page subscription
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    private function handleAdminPageSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement admin page subscription logic
    }

    /**
     * Handle admin users page subscription
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    private function handleAdminUsersPageSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement admin users page subscription logic
    }

    /**
     * Handle admin moderator page subscription
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    private function handleAdminModeratorPageSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement admin moderator page subscription logic
    }

    /**
     * Handle admin bots page subscription
     *
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    private function handleAdminBotsPageSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement admin bots page subscription logic
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
        if ($name === ChatCronConstants::CLEANUP_HISTORY) {
            $this->cleanupHistory();
        }
    }
}
