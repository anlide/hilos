<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;

/**
 * AbstractAgent - Abstract base class for agents running in worker processes.
 *
 * Provides base implementation for agents. Child classes should define:
 * - AGENT_TYPE constant - agent type identifier
 * - $agentIndex property (set in constructor) - for multi-instance agents
 * - doTick() - agent work logic (override this instead of tick())
 * - Signal handling methods (can override onSignal* methods for specific signal types).
 */
abstract class AbstractAgent implements AgentInterface, PageAgentInterface
{
    /** @var string Agent type identifier. Override in child classes. */
    public const string AGENT_TYPE = '';

    /** @var ?string Agent index for multi-instance agents (null for singletons) */
    protected ?string $agentIndex = null;

    /** @var bool Flag indicating agent should stop */
    private bool $shouldStop = false;

    /**
     * Get agent type identifier.
     *
     * @return string Agent type from AGENT_TYPE constant
     */
    public function getType(): string
    {
        return static::AGENT_TYPE;
    }

    /**
     * Get agent index (optional identifier for multi-instance agents).
     *
     * @return ?string Agent index or null for singleton agents
     */
    public function getIndex(): ?string
    {
        return $this->agentIndex;
    }

    /**
     * Get agent unique identifier (type + index).
     *
     * Default implementation: "type:index" or "type" if index is null.
     *
     * @return string Agent ID
     */
    public function getId(): string
    {
        $index = $this->getIndex();
        if ($index === null) {
            return $this->getType();
        }
        return $this->getType() . ':' . $index;
    }

    /**
     * Get signal source for this agent.
     *
     * @return SignalSourceInterface Agent signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(
            source: SignalSource::AGENT,
            type: $this->getType(),
            index: $this->getIndex(),
        );
    }

    /**
     * Send signal to a specific user (WebSocket connection by acceptKey).
     *
     * @param string $signalName Signal name (e.g. ChatSignalConstants::HANDSHAKE_RESPONSE)
     * @param string $targetAcceptKey Target connection acceptKey
     * @param SignalDataInterface $data Signal payload
     */
    public function sendToUser(string $signalName, string $targetAcceptKey, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $targetAcceptKey),
        );
    }

    /**
     * Send signal to all users (broadcast). Optionally exclude one connection.
     *
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     */
    public function sendToAllUsers(string $signalName, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Send signal to all users subscribed to a group.
     *
     * @param string $signalName Signal name
     * @param string $targetGroup Group name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     */
    public function sendToGroup(string $signalName, string $targetGroup, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_GROUP),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetGroup: $targetGroup, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Send signal to another agent (agent-to-agent).
     *
     * Target agent is determined by routing config: signals[AGENT][AGENT_SIGNAL][signalName].
     * Configure in application router (e.g. ChatSignalRouter::__construct).
     *
     * @param string $signalName Signal name (used for routing)
     * @param SignalDataInterface $data Signal payload
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName($signalName),
            signalData: new AgentSignalData(data: $data),
        );
    }

    /**
     * Request agent to stop itself.
     *
     * Sets internal flag that will cause onStop() to be called at the start
     * of the next tick. Agent will be removed from worker after onStop() is called.
     *
     * Child classes can call this method to request their own termination.
     */
    protected function selfStop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Check if agent has requested stop.
     *
     * @return bool True if agent has requested stop
     */
    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Default implementation - no action on start.
     *
     * Child classes can override this method.
     */
    public function onStart(): void
    {
        // Default: do nothing
    }

    /**
     * Called when agent is stopped.
     *
     * Must be implemented in child classes to handle cleanup.
     */
    abstract public function onStop(): void;

    /**
     * Default implementation - no system signal handling
     *
     * Child classes can override this method.
     *
     * @param SignalDataInterface $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalSystem(SignalDataInterface $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no handshake signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketHandshakeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no connection close signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketCloseSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no page subscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no page unsubscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketPageUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no page update subscription signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalPageUpdateSubscription(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no group subscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketGroupSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalGroupSubscribe(WebSocketGroupSubscribeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no group unsubscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketGroupUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalGroupUnsubscribe(WebSocketGroupUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no group update subscription signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalGroupUpdateSubscription(WebSocketGroupUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no action signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalAction(WebSocketActionSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no binary frame signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketFrameBinarySignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no cron signal handling
     *
     * Child classes can override this method.
     *
     * @param SignalDataInterface $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no agent-to-agent signal handling
     *
     * Child classes can override this method.
     * Use $data->data to access the inner payload (e.g. ModerationRequestSignalData).
     *
     * @param AgentSignalData $data Signal data (container with inner payload)
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no DB sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param DbSyncCreatedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no DB sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param DbSyncUpdatedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalDbSyncUpdated(DbSyncUpdatedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no DB sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param DbSyncDeletedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalDbSyncDeleted(DbSyncDeletedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no RT sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param RtSyncCreatedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no RT sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param RtSyncUpdatedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no RT sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param RtSyncDeletedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalRtSyncDeleted(RtSyncDeletedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }
}
