<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Agents;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Constants\ChatCronConstants;
use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Constants\HttpHeaders;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Core\Page\ChatPageContextInterface;
use Demo\WebSocketTest\Core\Page\ChatPageFactory;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\DTO\ChatEventSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractPageAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Actions\IdeaActionsCallbackNotSetException;
use Hilos\Exception\Idea\Actions\IdeaActionsDuplicateIdException;
use Hilos\Exception\Idea\Actions\IdeaActionsObjectCollectionNullException;
use Hilos\Exception\Idea\Actions\IdeaActionsTableNameUndeterminedException;
use Hilos\Exception\Idea\Actions\IdeaActionsUnknownLazyStrategyException;
use Hilos\Exception\Idea\Entity\IdeaEntityClassNotFoundException;
use Hilos\Exception\Idea\Entity\IdeaEntityMappingNotFoundException;
use Hilos\Exception\Idea\Entity\IdeaEntityTableConstantNotFoundException;
use Hilos\Exception\Idea\TruthSource\IdeaTruthSourceWriteNotAllowedException;
use Hilos\Logging\Logger\Logger;
use Hilos\Core\Router\SignalData;
use RuntimeException;

/**
 * ChatAgent - Monopolistic agent for chat management
 *
 * Runs in monopolistic worker process. Manages chat state and history.
 * State stored only in memory (no persistence).
 */
class ChatAgent extends AbstractPageAgent implements ChatPageContextInterface
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
     * Pending delayed page unsubscriptions (offline grace window).
     *
     * @var array<string,array{userId:int,page:string,queuedAt:int}>
     */
    private array $pendingPageUnsubscribes = [];

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
     * @throws IdeaEntityMappingNotFoundException If collection not found in mapping
     * @throws IdeaEntityClassNotFoundException If entity class does not exist
     * @throws IdeaEntityTableConstantNotFoundException If entity class does not have _table constant
     * @throws IdeaActionsCallbackNotSetException
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsDuplicateIdException If duplicate ID encountered
     */
    public function onStart(): void
    {
        // Register this agent as truth source for event and user tables (all keys)
        TruthSourceRegistry::register(Idea::getTableName(Idea::events), true, $this->getId());
        TruthSourceRegistry::register(Idea::getTableName(Idea::users), true, $this->getId());

        // Add chat started event to history (system event with userId = null)
        Idea::$idea->events->actions->add(ChatEventType::CHAT_STARTED->value);

        // Initialize page factory
        $this->setPageFactory(new ChatPageFactory($this->signalRouter, $this));
    }

    /**
     * Called when agent is stopped
     *
     * @throws DatabaseException If database operation fails
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsDuplicateIdException If duplicate ID is detected
     */
    public function onStop(): void
    {
        // Add chat stopped event to history (system event with userId = null)
        Idea::$idea->events->actions->add(ChatEventType::CHAT_STOPPED->value);
        TruthSourceRegistry::unregisterAgent($this->getId());
    }

    /**
     * Agent-specific tick implementation
     */
    public function onTick(): void
    {
        // TODO: Presence state tracking (offline grace + unstable links).
        // TODO: Keep a per-user presence state machine (online/unstable/offline),
        // TODO: backed by ping/pong timestamps and connection error counters.
        // TODO: Define thresholds for "unstable" (e.g. missed pings, reconnect churn),
        // TODO: and apply a 5s grace window before declaring offline.
        // TODO: Broadcast presence changes via EntitiesChangesDTO updates
        // TODO: (green = online, yellow = unstable, grey = offline).
        // TODO: Ensure state is per user, not per client, and merges multiple tabs.

        if ($this->pendingPageUnsubscribes === []) {
            return;
        }

        $now = time();
        foreach ($this->pendingPageUnsubscribes as $clientId => $pending) {
            if ($now - $pending['queuedAt'] < 5) {
                continue;
            }

            unset($this->pendingPageUnsubscribes[$clientId]);

            $userId = $pending['userId'];
            $page = $pending['page'];

            if ($this->hasOtherClientOnPage($userId, $page, $clientId)) {
                $this->forgetClientMapping($clientId);
                continue;
            }

            $pageFactory = $this->getPageFactory();
            if ($pageFactory !== null && $pageFactory->hasPage($page)) {
                $pageInstance = $pageFactory->getPage($page);
                $pageInstance->onUnsubscribe($clientId, $userId);
            }

            $this->removeClient($clientId);
        }
    }

    /**
     * Add event to history
     *
     * @param ChatEventType $type Event type
     * @param ?int $userId User ID (null for system events)
     * @param ?array $data Event-specific data (optional)
     * @param ?array $entities Entity updates for broadcast (optional)
     * @param ?string $excludeClientId Client ID to exclude from receiving the event (optional)
     * @throws DatabaseException If database operation fails
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsDuplicateIdException If duplicate ID is detected
     */
    public function addEvent(
        ChatEventType $type,
        ?int $userId = null,
        ?array $data = null,
        ?EntitiesChangesDTO $entities = null,
        ?string $excludeClientId = null,
    ): void
    {
        // Add event to collection (saves to database and adds to collection)
        $event = Idea::$idea->events->actions->add($type->value, $userId, $data);

        // Broadcast event to all connected clients via SignalRouter
        $eventData = new ChatEventSignalDTO($event, $entities);

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
     * Get online user IDs (optionally for a specific page)
     *
     * @param ?string $page Page name to filter by (null for all pages)
     * @return int[] Online user IDs
     */
    public function getOnlineUserIds(?string $page = null): array
    {
        $uniqueUserIds = [];

        foreach ($this->getClientIdsForPage($page) as $clientId) {
            $sessionToken = $this->clientIdToSessionToken[$clientId] ?? null;
            if ($sessionToken === null) {
                continue;
            }

            $userId = $this->sessionTokenToUserId[$sessionToken] ?? null;
            if ($userId === null) {
                continue;
            }

            $uniqueUserIds[$userId] = true;
        }

        return array_map('intval', array_keys($uniqueUserIds));
    }

    /**
     * Check if user has another client on page
     *
     * @param int $userId User id
     * @param string $page Page name
     * @param string $excludeClientId Client id to exclude
     * @return bool
     */
    public function hasOtherClientOnPage(int $userId, string $page, string $excludeClientId): bool
    {
        foreach ($this->getClientIdsForPage($page) as $clientId) {
            if ($clientId === $excludeClientId) {
                continue;
            }

            $sessionToken = $this->clientIdToSessionToken[$clientId] ?? null;
            if ($sessionToken === null) {
                continue;
            }

            $mappedUserId = $this->sessionTokenToUserId[$sessionToken] ?? null;
            if ($mappedUserId === null || $mappedUserId !== $userId) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Cleanup chat history
     * @throws DatabaseException If database operation fails
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     * @throws IdeaActionsDuplicateIdException If duplicate ID is detected
     */
    private function cleanupHistory(): void
    {
        Idea::$idea->events->actions->deleteAll();

        // Add ChatClearedEvent as a system event (userId = null for system events)
        $this->addEvent(ChatEventType::CHAT_CLEARED);
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
     * @throws IdeaActionsCallbackNotSetException If callback is not set
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
    private function rememberClientMapping(string $clientId, string $sessionToken, int $userId): void
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
     * Forget clientId mappings (e.g. on disconnect).
     *
     * @param string $clientId WebSocket client id
     */
    private function forgetClientMapping(string $clientId): void
    {
        unset($this->clientIdToSessionToken[$clientId]);
    }

    /**
     * Send subscription status update to client
     *
     * @param string $clientId Client id
     * @param string $page Page name
     * @param string $status Subscription status
     */
    private function sendSubscriptionStatus(string $clientId, string $page, string $status): void
    {
        $data = new SignalData([
            'page' => $page,
            'status' => $status,
        ]);

        $this->signalRouter->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName('subscription_updated'),
            signalData: new WebSocketSignalData(
                data: $data,
                targetClientId: $clientId,
                targetGroup: null,
                excludeClientId: null,
            ),
        );
    }

    /**
     * Provide user for handshake-based subscription.
     *
     * @param WebSocketHandshakeSignalDTO $data
     * @param string $clientId
     * @param string $page
     * @return mixed
     * @throws DatabaseException If user registration fails
     */
    protected function handleHandshakeSubscription(
        WebSocketHandshakeSignalDTO $data,
        string $clientId,
        string $page,
    ): mixed {
        $sessionToken = $this->getValidatedSessionToken($data);
        $user = $this->getOrRegisterUserBySessionToken($sessionToken);
        $this->rememberClientMapping($clientId, $sessionToken, $user->id);
        return $user;
    }

    /**
     * Resolve user object by client id for non-handshake subscribe.
     *
     * @param string $clientId
     * @return ?IdeaUser
     */
    protected function resolveUserForClient(string $clientId): mixed
    {
        $userId = $this->resolveUserIdByClientId($clientId);
        if ($userId === null) {
            return null;
        }

        $user = Idea::$idea->users[$userId];
        if (!$user instanceof IdeaUser) {
            return null;
        }

        return $user;
    }

    /**
     * Resolve user id by client id.
     *
     * @param string $clientId
     * @return ?int
     */
    protected function resolveUserIdForClient(string $clientId): ?int
    {
        return $this->resolveUserIdByClientId($clientId);
    }

    /**
     * Clear pending grace window on resubscribe/update.
     *
     * @param string $clientId
     * @param ?string $page
     * @return void
     */
    protected function onClientResubscribed(string $clientId, ?string $page): void
    {
        unset($this->pendingPageUnsubscribes[$clientId]);
    }

    /**
     * Notify client on successful subscribe.
     *
     * @param string $clientId
     * @param string $page
     * @param mixed $user
     * @return void
     */
    protected function onClientSubscribed(string $clientId, string $page, mixed $user): void
    {
        $this->sendSubscriptionStatus($clientId, $page, 'subscribed');
    }

    /**
     * Notify client on subscription update.
     *
     * @param string $clientId
     * @param string $page
     * @return void
     */
    protected function onClientSubscriptionUpdated(string $clientId, string $page): void
    {
        $this->sendSubscriptionStatus($clientId, $page, 'updated');
    }

    /**
     * Defer unsubscribe for main page (offline grace).
     *
     * @param string $clientId
     * @param int $userId
     * @param string $page
     * @return bool
     */
    protected function beforePageUnsubscribe(string $clientId, int $userId, string $page): bool
    {
        if ($page !== PageConstants::MAIN) {
            return false;
        }

        $this->pendingPageUnsubscribes[$clientId] = [
            'userId' => $userId,
            'page' => $page,
            'queuedAt' => time(),
        ];

        return true;
    }

    /**
     * Remove client-specific mappings after unsubscribe.
     *
     * @param string $clientId
     * @return void
     */
    protected function onClientRemoved(string $clientId): void
    {
        $this->forgetClientMapping($clientId);
    }

    /**
     * Log action signal details before dispatch.
     *
     * @param string $source
     * @param string $name
     * @param string $clientId
     * @param int $userId
     * @param string $payload
     * @return void
     */
    protected function onActionReceived(
        string $source,
        string $name,
        string $clientId,
        int $userId,
        string $payload,
    ): void {
        Logger::logAgentInfo(
            $this->getId(),
            "Action signal received: source={$source}, name={$name}, client={$clientId}, userId={$userId}, payload=" . json_encode($payload),
        );
    }

    /**
     * Handle cron signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     * @throws DatabaseException If database operation fails
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     * @throws IdeaActionsDuplicateIdException If duplicate ID is detected
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
