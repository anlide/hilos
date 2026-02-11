<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Core\Page\ChatPageAgentInterface;
use Demo\Chat\Database\Idea;
use Demo\Chat\Database\IdeaCollection\Events as IdeaEvents;
use Demo\Chat\Database\IdeaCollection\Users as IdeaUsers;
use Demo\Chat\DTO\ChatEventSignalDTO;
use Demo\Chat\DTO\HandshakeResponseSignalData;
use Demo\Chat\Runtime\ChatRuntime;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\DTO\WebSocket\WebSocketCloseSignalDTO;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Actions\IdeaActionsCallbackNotSetException;
use Hilos\Exception\Idea\Actions\IdeaActionsDuplicateIdException;
use Hilos\Exception\Idea\Actions\IdeaActionsObjectCollectionNullException;
use Hilos\Exception\Idea\Actions\IdeaActionsTableNameUndeterminedException;
use Hilos\Exception\Idea\Actions\IdeaActionsUnknownLazyStrategyException;
use Hilos\Exception\Idea\Collection\IdeaCollectionNotManualException;
use Hilos\Exception\Idea\Entity\IdeaEntityClassNotFoundException;
use Hilos\Exception\Idea\Entity\IdeaEntityMappingNotFoundException;
use Hilos\Exception\Idea\Entity\IdeaEntityTableConstantNotFoundException;
use Hilos\Exception\Idea\TruthSource\IdeaTruthSourceWriteNotAllowedException;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsCallbackNotSetException;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsCollectionNameNullException;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsStateCollectionNullException;
use Hilos\Exception\Runtime\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Logging\Logger\Logger;
use Hilos\Runtime\RtTruthSourceRegistry;

/**
 * ChatAgent - Monopolistic agent for chat management
 *
 * Runs in monopolistic worker process. Manages chat state and history.
 * State stored only in memory (no persistence).
 */
class ChatAgent extends AbstractAgent implements ChatPageAgentInterface
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
        // Register this agent as truth source for database tables (all keys)
        TruthSourceRegistry::register(Idea::getTableName(Idea::events), true, $this->getId());
        TruthSourceRegistry::register(Idea::getTableName(Idea::users), true, $this->getId());

        // Register this agent as truth source for runtime collections (all keys)
        RtTruthSourceRegistry::register(ChatRuntime::connections, true, $this->getId());

        // Add chat started event to history (system event with userId = null)
        Idea::$db->events->actions->add(ChatEventType::CHAT_STARTED->value);
    }

    /**
     * Handle handshake signal.
     *
     * @param WebSocketHandshakeSignalDTO $data
     * @param string $source
     * @param string $name
     * @return void Created connection wrapper
     * @throws DatabaseException If database operation fails
     * @throws IdeaActionsCallbackNotSetException If callback is not set
     * @throws IdeaActionsDuplicateIdException If duplicate ID is detected
     * @throws IdeaActionsObjectCollectionNullException If ObjectCollection is null
     * @throws IdeaActionsTableNameUndeterminedException If table name cannot be determined
     * @throws IdeaActionsUnknownLazyStrategyException If unknown lazy loading strategy
     * @throws IdeaRtActionsCallbackNotSetException If callback for creating IdeaRtItem is not set
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaTruthSourceWriteNotAllowedException If write is not allowed
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     * @throws IdeaCollectionNotManualException If collection is not manual, or item has no ID
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

        $wasAlreadyKnown = isset(Idea::$rt->connections->relevantUsers[$user->id]);

        // Register connection in runtime
        Idea::$rt->connections->actions->register($data->acceptKey, $user->id);

        $entities = !$wasAlreadyKnown
            ? new EntitiesChangesDTO()->withFull(Idea::users, IdeaUsers::fromSingleItem($user))
            : null;

        $this->addEvent(ChatEventType::USER_JOINED, $user->id, null, $entities, $data->acceptKey);

        // Handshake sends only the current (authorized) user; events + all users are sent from MainPage on subscribe
        $handshakeEntities = new EntitiesChangesDTO()->withFull(Idea::users, IdeaUsers::fromSingleItem($user));
        $subscriptionData = new HandshakeResponseSignalData(entities: $handshakeEntities, userId: $user->id);
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
     * Handle connection close signal (WebSocket connection closed).
     * Unregisters connection from runtime so that relevantUsers and state stay correct.
     *
     * @param WebSocketCloseSignalDTO $data
     * @param string $source
     * @param string $name
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        Idea::$rt->connections->actions->unregister($data->acceptKey);
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
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaRtActionsCallbackNotSetException If runtime callback is not set
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no runtime truth source registered
     */
    public function onStop(): void
    {
        // Add chat stopped event to history (system event with userId = null)
        Idea::$db->events->actions->add(ChatEventType::CHAT_STOPPED->value);

        // Clear all connections before unregistering
        Idea::$rt->connections->actions->clear();

        // Unregister from both database and runtime truth source registries
        TruthSourceRegistry::unregisterAgent($this->getId());
        RtTruthSourceRegistry::unregisterAgent($this->getId());
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
     * @throws IdeaCollectionNotManualException If collection is not manual, or item has no ID
     */
    public function addEvent(
        ChatEventType $type,
        ?int $userId = null,
        ?array $data = null,
        ?EntitiesChangesDTO $entities = null,
        ?string $excludeAcceptKey = null,
    ): void
    {
        $event = Idea::$db->events->actions->add($type->value, $userId, $data);

        $base = $entities ?? new EntitiesChangesDTO();
        $mergedEntities = $base->withFullAppended(
            Idea::events,
            IdeaEvents::fromSingleItem($event),
        );

        $eventData = new ChatEventSignalDTO($mergedEntities);

        // Wrap event data in WebSocketSignalData for WebSocket routing
        $this->signalRouter->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL),
            signalName: new SignalName(ChatSignalConstants::NEW_EVENT),
            signalData: new WebSocketSignalData(
                data: $eventData,
                excludeAcceptKey: $excludeAcceptKey,
            ),
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
     * @throws IdeaCollectionNotManualException If collection is not manual, or item has no ID
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        // Handle cleanup cron task
        if ($name === ChatCronConstants::CLEANUP_HISTORY) {
            Idea::$db->events->actions->deleteAll();

            // Add ChatClearedEvent as a system event (userId = null for system events).
            // Entities with replaceFull so frontend replaces events list with this single event.
            $entities = new EntitiesChangesDTO(replaceFullKeys: [Idea::events]);
            $this->addEvent(ChatEventType::CHAT_CLEARED, null, null, $entities);
        }
    }
}
