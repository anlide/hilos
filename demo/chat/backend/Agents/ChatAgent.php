<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Database\Idea;
use Demo\Chat\Database\IdeaCollection\Events as IdeaEvents;
use Demo\Chat\Database\IdeaCollection\Users as IdeaUsers;
use Demo\Chat\DTO\ChatEventSignalDTO;
use Demo\Chat\DTO\HandshakeResponseSignalData;
use Demo\Chat\Runtime\ChatRuntime;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\DTO\WebSocket\WebSocketCloseSignalDTO;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
use Hilos\Exception\HilosException;
use Hilos\Logging\Logger\Logger;
use Hilos\Runtime\RtTruthSourceRegistry;

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
     *
     * @throws HilosException If database operation fails or truth source registration fails
     */
    public function onStart(): void
    {
        // Register this agent as truth source for database tables (all keys)
        TruthSourceRegistry::register(Idea::events, true, $this->getId());
        TruthSourceRegistry::register(Idea::users, true, $this->getId());

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
     * @throws HilosException If database, runtime or truth source check fails
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        $sessionToken = $data->queryParams[HttpHeaders::SESSION_TOKEN] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            Logger::logAgentError($this->getId(), HttpHeaders::SESSION_TOKEN . " is required but not provided or empty");
            return;
        }

        $user = Idea::$db->users->findBySession($sessionToken);
        $wasRegisteredNow = false;
        if ($user === null) {
            $user = Idea::$db->users->actions->register($sessionToken);
            $wasRegisteredNow = true;
        }

        $hadNoConnections = count(Idea::$rt->connections->forUser($user->id)) === 0;
        Idea::$rt->connections->actions->register($data->acceptKey, $user->id);

        $userEntities = new EntitiesChangesDTO(full: [Idea::users => IdeaUsers::fromSingleItem($user)]);
        $newEvents = IdeaEvents::initEmpty();
        if ($wasRegisteredNow) {
            $newEvents->add(Idea::$db->events->actions->add(ChatEventType::USER_REGISTERED->value, $user->id));
        }
        if ($hadNoConnections) {
            $newEvents->add(Idea::$db->events->actions->add(ChatEventType::USER_ONLINE->value, $user->id));
        }

        if (count($newEvents) > 0) {
            $this->sendToAllUsers(
                ChatSignalConstants::NEW_EVENT,
                new ChatEventSignalDTO($userEntities->withFullAppended(Idea::events, $newEvents)),
                $data->acceptKey,
            );
        }

        $this->sendToUser(
            ChatSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                entities: $userEntities,
                userId: $user->id,
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
     * @throws HilosException If runtime unregister fails
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        if (!isset(Idea::$rt->connections[$data->acceptKey])) {
            return;
        }

        $userId = Idea::$rt->connections[$data->acceptKey]->userId;
        Idea::$rt->connections->actions->unregister($data->acceptKey);

        if (count(Idea::$rt->connections->forUser($userId)) === 0) {
            $event = Idea::$db->events->actions->add(ChatEventType::USER_OFFLINE->value, $userId);
            $user = Idea::$db->users[$userId] ?? null;
            if ($user === null) {
                return;
            }

            $this->sendToAllUsers(
                ChatSignalConstants::NEW_EVENT,
                new ChatEventSignalDTO(
                    new EntitiesChangesDTO(
                        full: [
                            Idea::users => IdeaUsers::fromSingleItem($user),
                            Idea::events => IdeaEvents::fromSingleItem($event),
                        ],
                    ),
                ),
            );
        }
    }

    /**
     * Called when agent is stopped
     *
     * @throws HilosException If database operation or unregistration fails
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
     * Handle cron signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     * @throws HilosException If database or truth source check fails
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        // Handle cleanup cron task
        if ($name === ChatCronConstants::CLEANUP_HISTORY) {
            Idea::$db->events->actions->deleteAll();

            $event = Idea::$db->events->actions->add(ChatEventType::CHAT_CLEARED->value);

            $this->sendToAllUsers(
                ChatSignalConstants::NEW_EVENT,
                new ChatEventSignalDTO(
                    new EntitiesChangesDTO(
                        full: [Idea::events => IdeaEvents::fromSingleItem($event)],
                        replaceFullKeys: [Idea::events],
                    ),
                ),
            );
        }
    }
}
