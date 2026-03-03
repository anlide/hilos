<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\API\Router\Exception\GroupSubscriptionNotFoundException;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Utils\Logger;

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

    /** @var bool Whether DB sync broadcast is enabled (false for CLI/migrations) */
    public bool $dbSyncBroadcastEnabled = true {
        get {
            return $this->dbSyncBroadcastEnabled;
        }
        set {
            $this->dbSyncBroadcastEnabled = $value;
        }
    }

    /** @var array<string, true> Keys "collectionKey:idString" for self-broadcast skip */
    private array $dbSyncBroadcastedIds = [];

    /** @var array<string, true> Keys "collectionKey:stateId" for self-broadcast skip */
    private array $rtSyncBroadcastedIds = [];

    /**
     * User page subscriptions storage
     * Format: [acceptKey => ['page' => string, 'params' => array]]
     *
     * @var array
     */
    private array $subscriptionPages = [];

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
     * @return list<array{agentType: string, agentIndex: ?string}> List of routes, or empty list if no route
     */
    public function route(SignalSourceInterface $signalSource, SignalTypeInterface $signalType, SignalDataInterface $dto): array
    {
        $source = $signalSource->getSource();
        $signalTypeValue = $signalType->getType();

        $signalsConfig = $this->config['signals'] ?? [];

        if (!isset($signalsConfig[$source][$signalTypeValue])) {
            return [];
        }

        $routeConfig = $signalsConfig[$source][$signalTypeValue];

        if (is_string($routeConfig)) {
            return [
                ['agentType' => $routeConfig, 'agentIndex' => null],
            ];
        }

        if (is_array($routeConfig)) {
            $routes = [];
            foreach ($routeConfig as $agentType) {
                if (is_string($agentType)) {
                    $routes[] = ['agentType' => $agentType, 'agentIndex' => null];
                }
            }
            return $routes;
        }

        return [];
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
     * Queue DB sync signal (from Object_::sync/delete).
     * Skips if broadcast disabled. Registers (collectionKey, idString) for self-apply skip.
     *
     * @param string $signalName Signal name (e.g. SignalConstants::DB_SYNC_CREATED)
     */
    public function queueDbSyncSignal(string $signalName, SignalDataInterface $signalData): void
    {
        if (!$this->dbSyncBroadcastEnabled) {
            return;
        }

        $data = $signalData->toArray();
        $collectionKey = $data['collectionKey'] ?? '';
        $idString = $data['idString'] ?? '';
        if ($collectionKey !== '' && $idString !== '') {
            $this->dbSyncBroadcastedIds[$collectionKey . ':' . $idString] = true;
        }

        $this->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType($signalName),
            signalName: new SignalName($signalName),
            signalData: $signalData,
        );
    }

    /**
     * Check if apply should be skipped (self-broadcast) and remove from registry.
     *
     * @return bool True if this was our broadcast, skip apply
     */
    public function shouldSkipDbSyncApply(string $collectionKey, string $idString): bool
    {
        $key = $collectionKey . ':' . $idString;
        if (isset($this->dbSyncBroadcastedIds[$key])) {
            unset($this->dbSyncBroadcastedIds[$key]);
            return true;
        }
        return false;
    }

    /**
     * Queue RT sync signal (from RtActions write operations).
     * Registers (collectionKey, stateId) for self-apply skip.
     *
     * @param string $signalName Signal name (e.g. SignalConstants::RT_SYNC_CREATED)
     */
    public function queueRtSyncSignal(string $signalName, SignalDataInterface $signalData): void
    {
        $data = $signalData->toArray();
        $collectionKey = $data['collectionKey'] ?? '';
        $stateId = $data['stateId'] ?? '';
        if ($collectionKey !== '' && $stateId !== '') {
            $this->rtSyncBroadcastedIds[$collectionKey . ':' . $stateId] = true;
        }

        $this->queueSignal(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType($signalName),
            signalName: new SignalName($signalName),
            signalData: $signalData,
        );
    }

    /**
     * Check if RT sync apply should be skipped (self-broadcast) and remove from registry.
     *
     * @return bool True if this was our broadcast, skip apply
     */
    public function shouldSkipRtSyncApply(string $collectionKey, string $stateId): bool
    {
        $key = $collectionKey . ':' . $stateId;
        if (isset($this->rtSyncBroadcastedIds[$key])) {
            unset($this->rtSyncBroadcastedIds[$key]);
            return true;
        }
        return false;
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
     * @param string $page Page identifier
     * @param WebSocketPageSubscribeSignalDTO $data
     */
    public function subscribeToPage(string $page, WebSocketPageSubscribeSignalDTO $data): void
    {
        $acceptKey = $data->acceptKey;
        if ($acceptKey === '') {
            return;
        }
        $this->subscriptionPages[$acceptKey] = [
            SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY => $page,
            SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY => $data->params,
        ];
    }

    /**
     * Update user page subscription
     *
     * Updates parameters of existing page subscription.
     * Throws exception if current page doesn't match the page being updated.
     *
     * @param string $page Page identifier
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data
     * @throws PageSubscriptionMismatchException If current page doesn't match the page being updated
     * @throws PageSubscriptionNotFoundException If no subscription found
     */
    public function updatePageSubscription(string $page, WebSocketPageUpdateSubscriptionSignalDTO $data): void
    {
        $acceptKey = $data->acceptKey;
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->subscriptionPages[$acceptKey])) {
            throw new PageSubscriptionNotFoundException($acceptKey);
        }

        $currentPage = $this->subscriptionPages[$acceptKey][SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY] ?? null;
        if ($currentPage !== $page) {
            throw new PageSubscriptionMismatchException($currentPage ?? '', $page);
        }

        // Merge new params with existing params
        $existingParams = $this->subscriptionPages[$acceptKey][SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY] ?? [];
        $this->subscriptionPages[$acceptKey][SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY] = array_merge($existingParams, $data->params);
    }

    /**
     * Subscribe user to group
     *
     * @param string $group Group identifier
     * @param WebSocketGroupSubscribeSignalDTO $data
     */
    public function subscribeToGroup(string $group, WebSocketGroupSubscribeSignalDTO $data): void
    {
        $acceptKey = $data->acceptKey;
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->subscriptionGroups[$acceptKey])) {
            $this->subscriptionGroups[$acceptKey] = [];
        }

        $this->subscriptionGroups[$acceptKey][$group] = $data->params;
    }

    /**
     * Update user group subscription
     *
     * Updates parameters of existing group subscription.
     * Throws exception if group is not currently subscribed.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data
     * @throws GroupSubscriptionNotFoundException If group is not currently subscribed
     */
    public function updateGroupSubscription(string $group, WebSocketGroupUpdateSubscriptionSignalDTO $data): void
    {
        $acceptKey = $data->acceptKey;
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->subscriptionGroups[$acceptKey]) || !isset($this->subscriptionGroups[$acceptKey][$group])) {
            throw new GroupSubscriptionNotFoundException($acceptKey, $group);
        }

        // Merge new params with existing params
        $existingParams = $this->subscriptionGroups[$acceptKey][$group];
        $this->subscriptionGroups[$acceptKey][$group] = array_merge($existingParams, $data->params);
    }


    /**
     * Unsubscribe user from page
     *
     * @param string $page
     * @param WebSocketPageUnsubscribeSignalDTO $data
     */
    public function unsubscribeFromPage(string $page, WebSocketPageUnsubscribeSignalDTO $data): void
    {
        $acceptKey = $data->acceptKey;
        if ($acceptKey === '') {
            return;
        }
        if (!isset($this->subscriptionPages[$acceptKey])) {
            return;
        }

        $currentPage = $this->subscriptionPages[$acceptKey][SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY] ?? null;
        if ($currentPage !== $page) {
            return;
        }

        unset($this->subscriptionPages[$acceptKey]);
    }

    /**
     * Unsubscribe user from group
     *
     * @param string $group Group identifier
     * @param WebSocketGroupUnsubscribeSignalDTO $data
     */
    public function unsubscribeFromGroup(string $group, WebSocketGroupUnsubscribeSignalDTO $data): void
    {
        $acceptKey = $data->acceptKey;
        if ($acceptKey === '') {
            return;
        }
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
        unset($this->subscriptionPages[$acceptKey]);
        unset($this->subscriptionGroups[$acceptKey]);
    }

    /**
     * Get destinations for signal
     *
     * Merges destinations from all routing methods. One signal can go to multiple
     * destination types (websocket, agent, worker, daemon) per config.
     *
     * @param SignalDTO $signal Signal DTO
     * @return array Array of destinations [['type' => string, ...], ...]
     */
    public function getDestinations(SignalDTO $signal): array
    {
        Logger::debug('getDestinations called for signal: ' . $signal->toJson());

        $signalType = $signal->signalType->getType();
        Logger::debug("getDestinations Signal type: " . $signalType);

        $destinations = [];

        if (in_array($signalType, [
            SignalTypeConstants::WS_USER,
            SignalTypeConstants::WS_ALL,
            SignalTypeConstants::WS_GROUP,
        ], true)) {
            $destinations = array_merge($destinations, $this->getWebSocketDestinations($signal));
        }

        if ($signalType === SignalTypeConstants::AGENT_SIGNAL) {
            $destinations = array_merge($destinations, $this->getAgentDestinations($signal));
        } else {
            $routes = $this->route($signal->signalSource, $signal->signalType, $signal->data);
            foreach ($routes as $route) {
                $destinations[] = array_merge(['type' => 'agent'], $route);
            }
        }

        return $destinations;
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
                foreach ($this->subscriptionPages as $acceptKey => $subscription) {
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

    /**
     * Get agent destinations for agent-to-agent signal
     *
     * Uses config['signals'][source][AGENT_SIGNAL][signalName] -> agentType or [agentTypes].
     * Configure in child router's __construct (e.g. ChatSignalRouter).
     * Supports single agent (string) or multiple agents (array of strings).
     *
     * @param SignalDTO $signal Signal DTO
     * @return array Array of destinations [['type' => 'agent', 'agentType' => string, 'agentIndex' => ?string], ...]
     */
    private function getAgentDestinations(SignalDTO $signal): array
    {
        $source = $signal->signalSource->getSource();
        $signalName = $signal->signalName->getName();

        $signalsConfig = $this->config['signals'] ?? [];
        $sourceConfig = $signalsConfig[$source] ?? [];
        $agentSignalsConfig = $sourceConfig[SignalTypeConstants::AGENT_SIGNAL] ?? null;

        if (!is_array($agentSignalsConfig) || !isset($agentSignalsConfig[$signalName])) {
            return [];
        }

        $agentTypes = $agentSignalsConfig[$signalName];
        if (is_string($agentTypes)) {
            $agentTypes = [$agentTypes];
        }
        if (!is_array($agentTypes)) {
            return [];
        }

        $destinations = [];
        foreach ($agentTypes as $agentType) {
            if (is_string($agentType) && $agentType !== '') {
                $destinations[] = [
                    'type' => 'agent',
                    'agentType' => $agentType,
                    'agentIndex' => null,
                ];
            }
        }

        return $destinations;
    }
}
