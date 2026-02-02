<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\WebSocket\WebSocketActionSignalDTO;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketSubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketUnsubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketUpdateSubscriptionSignalDTO;

/**
 * AbstractAgent - Abstract base class for agents running in worker processes
 *
 * Provides base implementation for agents. Child classes must implement:
 * - getType() - return agent type
 * - getIndex() - return agent index (can return null)
 * - doTick() - agent work logic (override this instead of tick())
 * - Signal handling methods (can override onSignal* methods for specific signal types)
 */
abstract class AbstractAgent implements AgentInterface
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
     * Default implementation - no page subscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketSubscribeSignalDTO $data Signal data
     */
    public function onSignalPageSubscribe(WebSocketSubscribeSignalDTO $data, string $source, string $name): void
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
     * @param WebSocketUnsubscribeSignalDTO $data Signal data
     */
    public function onSignalPageUnsubscribe(WebSocketUnsubscribeSignalDTO $data, string $source, string $name): void
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
     * @param WebSocketUpdateSubscriptionSignalDTO $data Signal data
     */
    public function onSignalPageUpdateSubscription(WebSocketUpdateSubscriptionSignalDTO $data, string $source, string $name): void
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
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupSubscribe(WebSocketSubscribeSignalDTO $data, string $source, string $name): void
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
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupUnsubscribe(WebSocketUnsubscribeSignalDTO $data, string $source, string $name): void
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
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupUpdateSubscription(WebSocketUpdateSubscriptionSignalDTO $data, string $source, string $name): void
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
