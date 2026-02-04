<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Agents;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Constants\ChatCronConstants;
use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\HttpHeaders;
use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\DTO\ChatEventSignalDTO;
use Demo\WebSocketTest\DTO\HandshakeResponseSignalData;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
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
        Idea::$db->events->actions->add(ChatEventType::CHAT_STARTED->value);

        // Page factory removed - will be redesigned separately.
    }

    /**
     * Handle handshake signal.
     *
     * @param string $source
     * @param string $name
     * @param WebSocketHandshakeSignalDTO $data
     * @throws DatabaseException If database operation fails
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        $sessionToken = $data->queryParams[HttpHeaders::SESSION_TOKEN] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            Logger::logAgentError($this->getId(), HttpHeaders::SESSION_TOKEN . " is required but not provided or empty");
            return;
        }

        $user = Idea::$db->users->findBySession($sessionToken);
        if ($user === null) {
            $user = Idea::$db->users->actions->register($sessionToken);
        }

        if ($user->id === null) {
            return;
        }

        $publicUser = $user->toArray(idAsIndex: false);
        unset($publicUser['sessionToken']);
        $entities = new EntitiesChangesDTO(
            full: ['users' => [$publicUser]],
        );

        $this->addEvent(ChatEventType::USER_JOINED, $user->id, null, $entities, $data->acceptKey);

        $users = Idea::$db->users->toArray(idAsIndex: false);
        $publicUsers = $this->toPublicUserArrayList($users);
        $subscriptionEntities = new EntitiesChangesDTO(
            full: ['users' => $publicUsers],
        );
        $subscriptionData = new HandshakeResponseSignalData(
            events: Idea::$db->events->toArray(idAsIndex: false),
            entities: $subscriptionEntities,
            userId: $user->id,
            username: $user->name,
        );

        $this->signalRouter->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(ChatSignalConstants::HANDSHAKE_RESPONSE),
            signalData: new WebSocketSignalData(
                data: $subscriptionData,
                targetAcceptKey: $data->acceptKey,
            ),
        );
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
        Idea::$db->events->actions->add(ChatEventType::CHAT_STOPPED->value);
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

        // Presence tracking removed for now.
    }

    /**
     * Add event to history
     *
     * @param ChatEventType $type Event type
     * @param ?int $userId User ID (null for system events)
     * @param ?array $data Event-specific data (optional)
     * @param ?EntitiesChangesDTO $entities Entity updates for broadcast (optional)
     * @param ?string $excludeAcceptKey Accept key to exclude from receiving the event (optional)
     * @throws DatabaseException If database operation fails
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     * @throws IdeaActionsDuplicateIdException If duplicate ID is detected
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     */
    public function addEvent(
        ChatEventType $type,
        ?int $userId = null,
        ?array $data = null,
        ?EntitiesChangesDTO $entities = null,
        ?string $excludeAcceptKey = null,
    ): void
    {
        // Add event to collection (saves to database and adds to collection)
        $event = Idea::$db->events->actions->add($type->value, $userId, $data);

        // Broadcast event to all connected clients via SignalRouter
        $eventData = new ChatEventSignalDTO($event, $entities);

        // Wrap event data in WebSocketSignalData for WebSocket routing
        $this->signalRouter->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL),
            signalName: new SignalName(ChatSignalConstants::NEW_EVENT),
            signalData: new WebSocketSignalData(
                data: $eventData,
                targetAcceptKey: null,
                targetGroup: null,
                excludeAcceptKey: $excludeAcceptKey,
            ),
        );
    }

    /**
     * Map user list to public arrays
     *
     * @param array $users
     * @return array
     */
    private function toPublicUserArrayList(array $users): array
    {
        return array_map(
            fn (array $user): array => $this->toPublicUserArray($user),
            $users,
        );
    }

    /**
     * Map user to public array
     *
     * @param array $user
     * @return array
     */
    private function toPublicUserArray(array $user): array
    {
        unset($user['sessionToken']);
        return $user;
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
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        // Handle cleanup cron task
        if ($name === ChatCronConstants::CLEANUP_HISTORY) {
            Idea::$db->events->actions->deleteAll();

            // Add ChatClearedEvent as a system event (userId = null for system events)
            $this->addEvent(ChatEventType::CHAT_CLEARED);
        }
    }
}
