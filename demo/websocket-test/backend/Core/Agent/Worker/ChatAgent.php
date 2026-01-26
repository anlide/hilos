<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Worker;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Constants\ChatCronConstants;
use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Constants\HttpHeaders;
use Demo\WebSocketTest\Core\Page\ChatPageContextInterface;
use Demo\WebSocketTest\Core\Page\ChatPageFactory;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\DTO\ChatEventSignalData;
use Demo\WebSocketTest\DTO\WebSocketFrameSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketHandshakeSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketUnsubscribeSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
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
class ChatAgent extends AbstractAgent implements ChatPageContextInterface
{
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
     * In-memory mapping between websocket client id and page name.
     * Filled during subscribe and used later in unsubscribe/action signals.
     *
     * @var array<string,string>
     */
    private array $clientIdToPage = [];

    /** @var ?ChatPageFactory Page factory instance */
    private ?ChatPageFactory $pageFactory = null;

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

        // Initialize page factory
        $this->pageFactory = new ChatPageFactory($this->signalRouter, $this);
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
    public function addEvent(ChatEventType $type, ?int $userId = null, ?array $data = null, ?string $excludeClientId = null): void
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
        $this->rememberClientMapping($clientId, $sessionToken, $user->id, $page);

        // Handle page-specific subscription logic via page handler
        if ($this->pageFactory === null || !$this->pageFactory->hasPage($page)) {
            Logger::logAgentError($this->getId(), "Unknown page subscription: {$page}");
            throw new RuntimeException("Unknown page subscription: {$page}");
        }

        $pageInstance = $this->pageFactory->getPage($page);
        $pageInstance->onSubscribe($clientId, $user);
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
     * @param string $page Page name
     */
    private function rememberClientMapping(string $clientId, string $sessionToken, int $userId, string $page): void
    {
        $this->clientIdToSessionToken[$clientId] = $sessionToken;
        $this->sessionTokenToUserId[$sessionToken] = $userId;
        $this->clientIdToPage[$clientId] = $page;
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
     * Resolve page name by clientId using internal mappings.
     *
     * @param string $clientId WebSocket client id
     * @return ?string Resolved page name or null if unknown
     */
    private function resolvePageByClientId(string $clientId): ?string
    {
        return $this->clientIdToPage[$clientId] ?? null;
    }

    /**
     * Forget clientId mappings (e.g. on disconnect).
     *
     * @param string $clientId WebSocket client id
     */
    private function forgetClientMapping(string $clientId): void
    {
        unset($this->clientIdToSessionToken[$clientId]);
        unset($this->clientIdToPage[$clientId]);
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

        // Resolve page from mapping (in case page name in signal doesn't match)
        $resolvedPage = $this->resolvePageByClientId($clientId) ?? $page;

        // Handle page-specific unsubscribe logic via page handler
        if ($this->pageFactory === null || !$this->pageFactory->hasPage($resolvedPage)) {
            Logger::logAgentError($this->getId(), "Unknown page unsubscribe: {$resolvedPage}");
            $this->forgetClientMapping($clientId);
            return;
        }

        $pageInstance = $this->pageFactory->getPage($resolvedPage);
        $pageInstance->onUnsubscribe($clientId, $userId);
        $this->forgetClientMapping($clientId);
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

        // Resolve page from mapping
        $page = $this->resolvePageByClientId($clientId);
        if ($page === null) {
            Logger::logAgentError($this->getId(), "Unable to resolve page for clientId={$clientId} (action)");
            return;
        }

        // Handle action via page handler
        if ($this->pageFactory === null || !$this->pageFactory->hasPage($page)) {
            Logger::logAgentError($this->getId(), "Unknown page for action: {$page}");
            return;
        }

        $pageInstance = $this->pageFactory->getPage($page);
        $pageInstance->onAction($clientId, $userId, $action, $payload);
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
