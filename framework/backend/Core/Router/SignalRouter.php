<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Constants\SignalTypeConstants;
use Hilos\DTO\SignalDTO;
use Hilos\Exception\Router\GroupSubscriptionNotFoundException;
use Hilos\Exception\Router\PageSubscriptionMismatchException;
use Hilos\Exception\Router\PageSubscriptionNotFoundException;
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

    /** @var SignalDTO[] Queued signals to dispatch */
    private array $queuedSignals = [];

    /**
     * User page subscriptions storage
     * Format: [acceptKey => ['page' => string, 'params' => array]]
     *
     * @var array
     */
    private array $subscriptions = [];

    /**
     * User group subscriptions storage
     * Format: [acceptKey => [groupName => params, ...]]
     *
     * @var array
     */
    private array $subscriptionGroups = [];

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
     * Supports routing by page, group, or direct routing.
     *
     * @param SignalSourceInterface $signalSource Signal source identifier
     * @param SignalTypeInterface $signalType Signal type (e.g., 'frame', 'handshake', 'close', 'subscribe', 'action')
     * @param SignalDataInterface $dto Signal data DTO
     * @return ?array Routing info ['agentType' => string, 'agentIndex' => ?string] or null if no route
     */
    public function route(SignalSourceInterface $signalSource, SignalTypeInterface $signalType, SignalDataInterface $dto): ?array
    {
        $source = $signalSource->getSource();
        $signalTypeValue = $signalType->getType();

        $signalsConfig = $this->config['signals'];

        // Check if source exists in config
        if (!isset($signalsConfig[$source])) {
            return null;
        }

        $sourceConfig = $signalsConfig[$source];

        // Check if signal type exists in source config
        if (!isset($sourceConfig[$signalTypeValue])) {
            return null;
        }

        $routeConfig = $sourceConfig[$signalTypeValue];

        if (is_string($routeConfig)) {
            return [
                'agentType' => $routeConfig,
                'agentIndex' => null,
            ];
        }

        return null;
    }

    /**
     * Queue signal for dispatch
     *
     * Called by servers to queue signals for later dispatch.
     * Signals are accumulated during tick and dispatched at the end of loop iteration.
     * Routing is performed during dispatch, not during queue.
     *
     * @param SignalSourceInterface $signalSource Signal source identifier
     * @param SignalTypeInterface $signalType Signal type (e.g., 'frame', 'handshake', 'close')
     * @param SignalNameInterface $signalName Signal name
     * @param SignalDataInterface $signalData Signal data DTO
     */
    public function queueSignal(SignalSourceInterface $signalSource, SignalTypeInterface $signalType, SignalNameInterface $signalName, SignalDataInterface $signalData): void
    {
        // Create SignalDTO and queue it
        $signal = new SignalDTO($signalSource, $signalType, $signalName, $signalData);
        $this->queuedSignals[] = $signal;

        // Log signal queued
        $source = $signalSource->getSource();
        $signalTypeValue = $signalType->getType();
        $signalNameValue = $signalName->getName();
        Logger::debug("Signal queued: {$source}/{$signalTypeValue}/{$signalNameValue}");
        Logger::debug('Now count of queued signals: ' . count($this->queuedSignals));
    }

    /**
     * Get next queued signal
     *
     * Returns one signal from queue and removes it.
     * Returns null if queue is empty.
     * Used by DaemonManager to dispatch signals one by one.
     *
     * @return ?SignalDTO Queued signal DTO or null
     */
    public function getNextQueuedSignal(): ?SignalDTO
    {
        if (empty($this->queuedSignals)) {
            return null;
        }

        Logger::debug('Was count of queued signals: ' . count($this->queuedSignals));
        return array_shift($this->queuedSignals);
    }

    /**
     * Subscribe user to page
     *
     * @param string $acceptKey Accept key identifier
     * @param string $page Page identifier
     * @param array $params Additional parameters
     */
    public function subscribeToPage(string $acceptKey, string $page, array $params = []): void
    {
        $this->subscriptions[$acceptKey] = [
            'page' => $page,
            'params' => $params,
        ];
    }

    /**
     * Update user page subscription
     *
     * Updates parameters of existing page subscription.
     * Throws exception if current page doesn't match the page being updated.
     *
     * @param string $acceptKey Accept key identifier
     * @param string $page Page identifier
     * @param array $params Additional parameters to update
     * @throws PageSubscriptionNotFoundException If no subscription found
     * @throws PageSubscriptionMismatchException If current page doesn't match the page being updated
     */
    public function updatePageSubscription(string $acceptKey, string $page, array $params): void
    {
        if (!isset($this->subscriptions[$acceptKey])) {
            throw new PageSubscriptionNotFoundException($acceptKey);
        }

        $currentPage = $this->subscriptions[$acceptKey]['page'] ?? null;
        if ($currentPage !== $page) {
            throw new PageSubscriptionMismatchException($currentPage ?? '', $page);
        }

        // Merge new params with existing params
        $existingParams = $this->subscriptions[$acceptKey]['params'] ?? [];
        $this->subscriptions[$acceptKey]['params'] = array_merge($existingParams, $params);
    }

    /**
     * Subscribe user to group
     *
     * @param string $acceptKey Accept key identifier
     * @param string $group Group identifier
     * @param array $params Additional parameters
     */
    public function subscribeToGroup(string $acceptKey, string $group, array $params = []): void
    {
        if (!isset($this->subscriptionGroups[$acceptKey])) {
            $this->subscriptionGroups[$acceptKey] = [];
        }

        $this->subscriptionGroups[$acceptKey][$group] = $params;
    }

    /**
     * Update user group subscription
     *
     * Updates parameters of existing group subscription.
     * Throws exception if group is not currently subscribed.
     *
     * @param string $acceptKey Accept key identifier
     * @param string $group Group identifier
     * @param array $params Additional parameters to update
     * @throws GroupSubscriptionNotFoundException If group is not currently subscribed
     */
    public function updateGroupSubscription(string $acceptKey, string $group, array $params): void
    {
        if (!isset($this->subscriptionGroups[$acceptKey]) || !isset($this->subscriptionGroups[$acceptKey][$group])) {
            throw new GroupSubscriptionNotFoundException($acceptKey, $group);
        }

        // Merge new params with existing params
        $existingParams = $this->subscriptionGroups[$acceptKey][$group];
        $this->subscriptionGroups[$acceptKey][$group] = array_merge($existingParams, $params);
    }


    /**
     * Unsubscribe user from page
     *
     * @param string $acceptKey Accept key identifier
     */
    public function unsubscribeFromPage(string $acceptKey): void
    {
        unset($this->subscriptions[$acceptKey]);
    }

    /**
     * Unsubscribe user from group
     *
     * @param string $acceptKey Accept key identifier
     * @param string $group Group identifier
     */
    public function unsubscribeFromGroup(string $acceptKey, string $group): void
    {
        if (isset($this->subscriptionGroups[$acceptKey])) {
            unset($this->subscriptionGroups[$acceptKey][$group]);

            // Clean up empty client entry
            if (empty($this->subscriptionGroups[$acceptKey])) {
                unset($this->subscriptionGroups[$acceptKey]);
            }
        }
    }


    /**
     * Unsubscribe user from all subscriptions
     *
     * @param string $acceptKey Accept key identifier
     */
    public function unsubscribeFromAll(string $acceptKey): void
    {
        unset($this->subscriptions[$acceptKey]);
        unset($this->subscriptionGroups[$acceptKey]);
    }

    /**
     * Get destinations for signal
     *
     * Returns array of destinations for signal based on routing configuration.
     * Each destination is an array with 'type', 'agentType' and 'agentIndex' (for agent routing)
     * or 'type' and 'acceptKey' (for WebSocket routing).
     * Can return multiple destinations for signals that need to be delivered to multiple agents or clients.
     *
     * @param SignalDTO $signal Signal DTO
     * @return array Array of destinations [['type' => string, ...], ...]
     */
    public function getDestinations(SignalDTO $signal): array
    {
        Logger::debug('getDestinations called for signal: ' . $signal->toJson());

        $signalType = $signal->signalType->getType();
        Logger::debug("getDestinations Signal type: " . $signalType);

        // Handle WebSocket response signals (ws_user, ws_all, ws_group)
        if (in_array($signalType, [
            SignalTypeConstants::WS_USER,
            SignalTypeConstants::WS_ALL,
            SignalTypeConstants::WS_GROUP,
        ], true)) {
            return $this->getWebSocketDestinations($signal);
        }

        // Get route using existing route method for agent routing
        $route = $this->route($signal->signalSource, $signal->signalType, $signal->data);

        if ($route === null) {
            return [];
        }

        // Add destination type (for now only 'agent' is supported)
        $destination = array_merge(['type' => 'agent'], $route);

        // For now, return single destination
        // Can be extended in child classes to return multiple destinations
        return [$destination];
    }

    /**
     * Get WebSocket destinations for signal
     *
     * Returns array of WebSocket client destinations based on signal type and subscriptions.
     * For ws_user: returns single client with targetAcceptKey
     * For ws_all: returns all subscribed clients, excluding excludeAcceptKey
     * For ws_group: returns clients subscribed to targetGroup, excluding excludeAcceptKey
     *
     * @param SignalDTO $signal Signal DTO
     * @return array Array of destinations [['type' => 'websocket', 'acceptKey' => string], ...]
     */
    private function getWebSocketDestinations(SignalDTO $signal): array
    {
        Logger::debug("Getting WebSocket destinations for signal type: " . $signal->toJson());
        $signalType = $signal->signalType->getType();
        $signalData = $signal->data;

        // Extract targeting info from WebSocketSignalData
        $targetAcceptKey = null;
        $targetGroup = null;
        $excludeAcceptKey = null;

        #var_dump($signalData);
        #var_dump($signalData instanceof WebSocketSignalData);
        if ($signalData instanceof WebSocketSignalData) {
            $targetAcceptKey = $signalData->targetAcceptKey;
            $targetGroup = $signalData->targetGroup;
            $excludeAcceptKey = $signalData->excludeAcceptKey;
        }

        $destinations = [];

        switch ($signalType) {
            case SignalTypeConstants::WS_USER:
                // Return single client destination
                if ($targetAcceptKey !== null && $targetAcceptKey !== '') {
                    $destinations[] = [
                        'type' => 'websocket',
                        'acceptKey' => $targetAcceptKey,
                    ];
                }
                break;

            case SignalTypeConstants::WS_ALL:
                // Return all subscribed clients, excluding excludeAcceptKey
                foreach ($this->subscriptions as $acceptKey => $subscription) {
                    if ($excludeAcceptKey !== null && $acceptKey === $excludeAcceptKey) {
                        continue;
                    }
                    $destinations[] = [
                        'type' => 'websocket',
                        'acceptKey' => $acceptKey,
                    ];
                }
                break;

            case SignalTypeConstants::WS_GROUP:
                // Return clients subscribed to targetGroup, excluding excludeAcceptKey
                if ($targetGroup !== null && $targetGroup !== '') {
                    foreach ($this->subscriptionGroups as $acceptKey => $groups) {
                        if ($excludeAcceptKey !== null && $acceptKey === $excludeAcceptKey) {
                            continue;
                        }
                        if (isset($groups[$targetGroup])) {
                            $destinations[] = [
                                'type' => 'websocket',
                                'acceptKey' => $acceptKey,
                            ];
                        }
                    }
                }
                break;
        }

        return $destinations;
    }
}
