<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Utils\DTO\SignalDTO;
use Hilos\Logging\Logger\Logger;

/**
 * SignalRouter - Base class for routing signals from sources to agents
 *
 * Routes signals based on configuration stored in protected $config array.
 * Can be used directly with empty config, or extended to provide custom routing rules.
 * Manages signal queueing.
 */
class SignalRouter
{
    /**
     * Signal routing configuration
     *
     * Can be overridden in child classes or set via constructor.
     * Format: [
     *     'source_name' => [
     *         'signal_type' => ['agentType' => 'type', 'agentIndex' => 'index'|null]
     *     ]
     * ]
     *
     * @var array
     */
    protected array $config;

    /** @var array Queued signals to dispatch [['signalSource' => SignalSourceInterface, 'signalType' => string, 'dto' => SignalDTO, 'route' => ?array], ...] */
    private array $queuedSignals = [];

    /**
     * Constructor
     *
     * Initializes signal router with empty configuration.
     * Child classes should override constructor and call parent::__construct(),
     * then set $this->config with custom routing rules.
     */
    public function __construct()
    {
        $this->config = [];
    }

    /**
     * Route signal to target
     *
     * Returns routing information for signal, or null if no route found.
     *
     * @param SignalSourceInterface $signalSource Signal source identifier
     * @param string $signalType Signal type (e.g., 'frame', 'handshake', 'close')
     * @param SignalDTO $dto Signal DTO
     * @return ?array Routing info ['agentType' => string, 'agentIndex' => ?string] or null if no route
     */
    public function route(SignalSourceInterface $signalSource, string $signalType, SignalDTO $dto): ?array
    {
        $source = $signalSource->getSource();

        // Check if source exists in config
        if (!isset($this->config[$source])) {
            return null;
        }

        $sourceConfig = $this->config[$source];

        // Check if signal type exists in source config
        if (!isset($sourceConfig[$signalType])) {
            return null;
        }

        $route = $sourceConfig[$signalType];

        // Validate route structure
        if (!isset($route['agentType'])) {
            return null;
        }

        return [
            'agentType' => $route['agentType'],
            'agentIndex' => $route['agentIndex'] ?? null,
        ];
    }

    /**
     * Check if route exists
     *
     * @param SignalSourceInterface $signalSource Signal source identifier
     * @param string $signalType Signal type
     * @return bool True if route exists
     */
    public function hasRoute(SignalSourceInterface $signalSource, string $signalType): bool
    {
        $source = $signalSource->getSource();

        // Check if source exists in config
        if (!isset($this->config[$source])) {
            return false;
        }

        $sourceConfig = $this->config[$source];

        // Check if signal type exists in source config
        if (!isset($sourceConfig[$signalType])) {
            return false;
        }

        $route = $sourceConfig[$signalType];

        // Validate route structure
        return isset($route['agentType']);
    }

    /**
     * Queue signal for dispatch
     *
     * Called by servers to queue signals for later dispatch.
     * Signals are accumulated during tick and dispatched at the end of loop iteration.
     *
     * @param SignalSourceInterface $signalSource Signal source identifier
     * @param string $signalType Signal type (e.g., 'frame', 'handshake', 'close')
     * @param SignalDTO $dto Signal DTO
     */
    public function queueSignal(SignalSourceInterface $signalSource, string $signalType, SignalDTO $dto): void
    {
        // Route signal to determine target
        $route = $this->route($signalSource, $signalType, $dto);

        // Queue signal with route info
        $this->queuedSignals[] = [
            'signalSource' => $signalSource,
            'signalType' => $signalType,
            'dto' => $dto,
            'route' => $route,
        ];

        // Log signal queued
        $source = $signalSource->getSource();
        $routeInfo = $route !== null
            ? "route to agent {$route['agentType']}" . ($route['agentIndex'] !== null ? "({$route['agentIndex']})" : "")
            : "no route";
        Logger::info("Signal queued: {$source}/{$signalType} -> {$routeInfo}");
    }

    /**
     * Get and clear queued signals
     *
     * Returns all queued signals and clears the queue.
     * Used by DaemonManager to dispatch signals.
     *
     * @return array Queued signals [['signalSource' => SignalSourceInterface, 'signalType' => string, 'dto' => SignalDTO, 'route' => ?array], ...]
     */
    public function getAndClearQueuedSignals(): array
    {
        $signals = $this->queuedSignals;
        $count = count($signals);

        if ($count > 0) {
            Logger::info("Signals dequeued: {$count} signal(s)");
        }

        $this->queuedSignals = [];
        return $signals;
    }
}

