<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\WebSocket\WebSocketActionSignalDTO;
use Hilos\DTO\WebSocket\WebSocketFrameBinarySignalDTO;
use Hilos\DTO\WebSocket\WebSocketCloseSignalDTO;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketGroupSubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\DTO\WebSocket\WebSocketPageSubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketPageUnsubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketPageUpdateSubscriptionSignalDTO;

/**
 * AbstractAgent - Abstract base class for agents running in worker processes
 *
 * Provides base implementation for agents. Child classes must implement:
 * - getType() - return agent type
 * - getIndex() - return agent index (can return null)
 * - doTick() - agent work logic (override this instead of tick())
 * - Signal handling methods (can override onSignal* methods for specific signal types)
 */
abstract class AbstractAgent implements AgentInterface, PageAgentInterface
{
    /** @var SignalRouter Signal router for queuing signals */
    protected SignalRouter $signalRouter;

    /** @var bool Flag indicating agent should stop */
    private bool $shouldStop = false;

    /**
     * Constructor
     *
     * @param SignalRouter $signalRouter Signal router instance
     */
    public function __construct(SignalRouter $signalRouter)
    {
        $this->signalRouter = $signalRouter;
    }

    /**
     * Get agent unique identifier (type + index)
     *
     * Default implementation: "type:index" or "type" if index is null
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
     * Get signal source for this agent
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
     * Request agent to stop itself
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
     * Check if agent has requested stop
     *
     * @return bool True if agent has requested stop
     */
    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Default implementation - no action on start
     *
     * Child classes can override this method.
     */
    public function onStart(): void
    {
        // Default: do nothing
    }

    /**
     * Called when agent is stopped
     *
     * Must be implemented in child classes to handle cleanup.
     */
    abstract public function onStop(): void;

    /**
     * Default implementation - no system signal handling
     *
     * Child classes can override this method.
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketHandshakeSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketCloseSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketPageUnsubscribeSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketGroupSubscribeSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketGroupUnsubscribeSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketActionSignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketFrameBinarySignalDTO $data Signal data
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
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        // Default: do nothing
    }
}
