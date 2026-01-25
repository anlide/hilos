<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Worker;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Constants\ChatCronConstants;
use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\HttpHeaders;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\DTO\ChatEventSignalData;
use Demo\WebSocketTest\DTO\SubscriptionResponseSignalData;
use Demo\WebSocketTest\DTO\WebSocketFrameSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketHandshakeSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketUnsubscribeSignalDTO;
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
    private const string ACTION_MESSAGE = ChatSignalConstants::MESSAGE;

    /**
     * In-memory mapping between session token and user id.
     * Filled during handshake (page subscribe) and used later in unsubscribe/action signals.
     *
     * @var array<string,int>
     */
    private array $sessionTokenToUserId = [];

    /**
     * In-memory mapping between websocket client id and session token.
     * Needed because unsubscribe/action signals do not carry session token.
     *
     * @var array<string,string>
     */
    private array $clientIdToSessionToken = [];

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

        // Get or register user from session token and remember mapping
        $sessionToken = $this->getValidatedSessionToken($handshakeData);
        $user = $this->getOrRegisterUserBySessionToken($sessionToken);
        $this->rememberClientSessionUserMapping($clientId, $sessionToken, $user->id);

        // Handle page-specific subscription logic
        $this->handlePageSubscribe($page, $clientId, $user);
    }

    /**
     * Extract and validate session token from handshake DTO.
     *
     * @param WebSocketHandshakeSignalDTO $handshakeData Handshake data containing session token
     * @return string Validated session token
     * @throws RuntimeException If session token is invalid
     */
    private function getValidatedSessionToken(WebSocketHandshakeSignalDTO $handshakeData): string
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

        return $sessionToken;
    }

    /**
     * Get or register user by session token.
     *
     * @param string $sessionToken Validated session token
     * @return IdeaUser User idea
     * @throws DatabaseException If user registration fails
     */
    private function getOrRegisterUserBySessionToken(string $sessionToken): IdeaUser
    {
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
     * Remember mapping for later signals (unsubscribe/action).
     *
     * @param string $clientId WebSocket client id
     * @param string $sessionToken Session token
     * @param int $userId User id
     */
    private function rememberClientSessionUserMapping(string $clientId, string $sessionToken, int $userId): void
    {
        $this->clientIdToSessionToken[$clientId] = $sessionToken;
        $this->sessionTokenToUserId[$sessionToken] = $userId;
    }

    /**
     * Resolve userId by clientId using internal mappings.
     *
     * @param string $clientId WebSocket client id
     * @return ?int Resolved user id or null if unknown
     */
    private function resolveUserIdByClientId(string $clientId): ?int
    {
        $sessionToken = $this->clientIdToSessionToken[$clientId] ?? null;
        if ($sessionToken === null || $sessionToken === '') {
            return null;
        }

        return $this->sessionTokenToUserId[$sessionToken] ?? null;
    }

    /**
     * Forget clientId -> sessionToken mapping (e.g. on disconnect).
     *
     * @param string $clientId WebSocket client id
     */
    private function forgetClientMapping(string $clientId): void
    {
        unset($this->clientIdToSessionToken[$clientId]);
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
        // Validate DTO type
        if (!($data instanceof WebSocketUnsubscribeSignalDTO)) {
            $dataType = get_class($data);
            Logger::logAgentError($this->getId(), "Invalid signal data type: expected WebSocketUnsubscribeSignalDTO, got {$dataType}");
            throw new RuntimeException("Expected WebSocketUnsubscribeSignalDTO, got {$dataType}");
        }

        $unsubscribeData = $data;
        $clientId = $unsubscribeData->clientId;
        $page = $name;

        Logger::logAgentDebug($this->getId(), "Page unsubscribe signal received: source={$source}, client={$clientId}, page={$page}");

        if ($clientId === '') {
            Logger::logAgentError($this->getId(), "Client ID is required but not provided or empty (page unsubscribe)");
            return;
        }

        $userId = $this->resolveUserIdByClientId($clientId);
        if ($userId === null) {
            Logger::logAgentError($this->getId(), "Unable to resolve userId for clientId={$clientId} (page unsubscribe)");
            $this->forgetClientMapping($clientId);
            return;
        }

        $this->handlePageUnsubscribe($page, $clientId, $userId);
        $this->forgetClientMapping($clientId);
    }

    /**
     * Handle page-specific unsubscribe logic.
     *
     * @param string $page Page name
     * @param string $clientId Client id
     * @param int $userId User id
     * @throws DatabaseException If database operation fails
     */
    private function handlePageUnsubscribe(string $page, string $clientId, int $userId): void
    {
        switch ($page) {
            case PageConstants::MAIN:
                $this->handleMainPageUnsubscribe($clientId, $userId);
                break;

            case PageConstants::PROFILE:
                $this->handleProfilePageUnsubscribe($clientId, $userId);
                break;

            case PageConstants::USER:
                $this->handleUserPageUnsubscribe($clientId, $userId);
                break;

            case PageConstants::BOT:
                $this->handleBotPageUnsubscribe($clientId, $userId);
                break;

            case PageConstants::MODERATOR:
                $this->handleModeratorPageUnsubscribe($clientId, $userId);
                break;

            case PageConstants::ADMIN:
                $this->handleAdminPageUnsubscribe($clientId, $userId);
                break;

            case PageConstants::ADMIN_USERS:
                $this->handleAdminUsersPageUnsubscribe($clientId, $userId);
                break;

            case PageConstants::ADMIN_MODERATOR:
                $this->handleAdminModeratorPageUnsubscribe($clientId, $userId);
                break;

            case PageConstants::ADMIN_BOTS:
                $this->handleAdminBotsPageUnsubscribe($clientId, $userId);
                break;

            default:
                Logger::logAgentError($this->getId(), "Unknown page unsubscribe: {$page}");
                throw new RuntimeException("Unknown page unsubscribe: {$page}");
        }
    }

    /**
     * Handle main page unsubscribe.
     *
     * @param string $clientId Client id
     * @param int $userId User id
     * @throws DatabaseException If database operation fails
     */
    private function handleMainPageUnsubscribe(string $clientId, int $userId): void
    {
        // Add user left event (clientId must NOT be included in event data)
        $this->addEvent(ChatEventType::USER_LEFT, $userId);
    }

    private function handleProfilePageUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement profile page unsubscribe logic
    }

    private function handleUserPageUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement user page unsubscribe logic
    }

    private function handleBotPageUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement bot page unsubscribe logic
    }

    private function handleModeratorPageUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement moderator page unsubscribe logic
    }

    private function handleAdminPageUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement admin page unsubscribe logic
    }

    private function handleAdminUsersPageUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement admin users page unsubscribe logic
    }

    private function handleAdminModeratorPageUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement admin moderator page unsubscribe logic
    }

    private function handleAdminBotsPageUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement admin bots page unsubscribe logic
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
        // Validate DTO type
        if (!($data instanceof WebSocketFrameSignalDTO)) {
            $dataType = get_class($data);
            Logger::logAgentError($this->getId(), "Invalid signal data type: expected WebSocketFrameSignalDTO, got {$dataType}");
            throw new RuntimeException("Expected WebSocketFrameSignalDTO, got {$dataType}");
        }

        $frameData = $data;
        $clientId = $frameData->clientId;
        $action = $name; // action name is carried by signal name (e.g. message/file/rename)
        $payload = $frameData->payload;

        if ($clientId === '') {
            Logger::logAgentError($this->getId(), "Client ID is required but not provided or empty (action)");
            return;
        }

        $userId = $this->resolveUserIdByClientId($clientId);
        if ($userId === null) {
            Logger::logAgentError($this->getId(), "Unable to resolve userId for clientId={$clientId} (action)");
            return;
        }

        Logger::logAgentInfo(
            $this->getId(),
            "Action signal received: source={$source}, name={$name}, client={$clientId}, userId={$userId}, payload=" . json_encode($payload),
        );

        switch ($action) {
            case self::ACTION_MESSAGE:
                $this->handleActionMessage($clientId, $userId, $payload);
                break;

            default:
                // TODO: add dedicated handlers per action (file/rename/etc)
                Logger::logAgentError($this->getId(), "Unknown action: {$action}");
                break;
        }
    }

    /**
     * Handle "message" action.
     * Expects JSON payload like: { "type": "message", "content": "..." } (frontend)
     *
     * @param string $clientId Client id
     * @param int $userId User id
     * @param string $payload Raw websocket payload
     * @throws DatabaseException If database operation fails
     */
    private function handleActionMessage(string $clientId, int $userId, string $payload): void
    {
        $payloadData = $this->tryDecodeJsonPayload($payload);
        if ($payloadData === null) {
            Logger::logAgentError($this->getId(), "Invalid JSON payload for message action (clientId={$clientId})");
            return;
        }

        // We accept both: {content:"..."} and {data:{message:"..."}} for forward/backward compatibility
        $content = $payloadData['content'] ?? null;
        if ($content === null && isset($payloadData['data']) && is_array($payloadData['data'])) {
            $content = $payloadData['data']['message'] ?? null;
        }

        if (!is_string($content) || trim($content) === '') {
            Logger::logAgentError($this->getId(), "Empty message content (clientId={$clientId}, userId={$userId})");
            return;
        }

        $this->addEvent(ChatEventType::MESSAGE_SENT, $userId, [
            'message' => $content,
        ]);
    }

    /**
     * Try to decode JSON payload into associative array.
     *
     * @param string $payload Raw websocket payload
     * @return ?array<string,mixed> Decoded payload or null if invalid JSON
     */
    private function tryDecodeJsonPayload(string $payload): ?array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
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
