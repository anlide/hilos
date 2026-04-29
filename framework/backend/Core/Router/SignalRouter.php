<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\API\Router\Exception\GroupSubscriptionNotFoundException;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Utils\Logger;

/**
 * SignalRouter - Base class for routing signals from sources to agents.
 *
 * Routes signals based on configuration stored in protected $config array.
 * Extended by project-level routers (e.g. ChatSignalRouter) to define routing rules.
 *
 * Routing design principle: route by sender, not by destination.
 * Signal source and type determine which agent receives the signal.
 * Agents do not pull signals — they receive them based on declarative routing config.
 *
 * Config structure:
 * - 'pages'    — page -> agentType mapping (which agent handles which page)
 * - 'groups'   — group -> agentType mapping
 * - 'signals'  — source -> signalType -> agentType mapping (static routing)
 * - 'actions'  — action -> agentType mapping
 * - 'page_subscription_routing' — per-page agent override for PAGE_SUBSCRIBE/UNSUBSCRIBE/UPDATE:
 *       ['default' => agentType, 'pages' => [pageName => agentType, ...]]
 * - 'table_event_routes' — eventKey -> table keys affected by the event
 *
 * For dynamic routing (agentIndex depends on signal content), override getDestinations()
 * in child router. Static routing must always be declared in config.
 */
class SignalRouter
{
    /**
     * Signal routing configuration
     *
     * Set by child router in __construct(). Keys:
     * - 'pages'  — array<string, array{agentType: string, agentIndex: ?string, params: array}>
     * - 'groups' — array<string, array{agentType: string, agentIndex: ?string, params: array}>
     * - 'signals' — array<source, array<signalType, string|string[]>> (static agent routing)
     * - 'actions' — array<actionName, string> (action -> agentType)
     * - 'page_subscription_routing' — array{default: string, pages: array<pageName, string>}
     * - 'table_event_routes' — array<eventKey, list<tableKey>>
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /** @var list<SignalDTO> queued signals to dispatch */
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
     *
     * Format: [acceptKey => [pageKey => string, paramsKey => array<string, mixed>]]
     *
     * @var array<string, array<string, mixed>>
     */
    private array $subscriptionPages = [];

    /**
     * User group subscriptions storage
     *
     * Format: [acceptKey => [groupName => params, ...]]
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $subscriptionGroups = [];

    private ?SignalMapperInterface $emitMapper = null;

    /**
     * Creates signal router with empty configuration.
     *
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
        $signal = new SignalDTO(
            $signalSource,
            $signalType,
            $signalName,
            $signalData,
            Hilos::$ac?->captureSignalMeta() ?? [],
        );
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
     * @param SignalDataInterface $signalData Signal data with collectionKey and idString
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
     * @param string $collectionKey Collection key for sync
     * @param string $idString Entity ID string
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
     * @param SignalDataInterface $signalData Signal data with collectionKey and stateId
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
     * @param string $collectionKey Collection key for sync
     * @param string $stateId Runtime state ID
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
     * Subscribe user to page.
     *
     * @param string $page Page identifier
     * @param WebSocketPageSubscribeSignalDTO $data Subscribe signal (acceptKey, params)
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
     * Update user page subscription.
     *
     * Updates parameters of existing page subscription.
     * Throws exception if current page doesn't match the page being updated.
     *
     * @param string $page Page identifier
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Update signal (acceptKey, params)
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
     * Subscribe user to group.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupSubscribeSignalDTO $data Subscribe signal (acceptKey, params)
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
     * Update user group subscription.
     *
     * Updates parameters of existing group subscription.
     * Throws exception if group is not currently subscribed.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Update signal (acceptKey, params)
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
     * Unsubscribe user from page.
     *
     * @param string $page Page identifier
     * @param WebSocketPageUnsubscribeSignalDTO $data Unsubscribe signal (acceptKey)
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
     * Unsubscribe user from group.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupUnsubscribeSignalDTO $data Unsubscribe signal (acceptKey)
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
     * Register mapper that expands EMIT_* signals to WebSocket fan-out on the daemon.
     *
     * @param ?SignalMapperInterface $emitMapper Emit mapper, or null to disable emit fan-out mapping
     */
    public function setEmitMapper(?SignalMapperInterface $emitMapper): void
    {
        $this->emitMapper = $emitMapper;
    }

    /**
     * Returns mapper that expands EMIT_* signals to WebSocket fan-out on the daemon.
     *
     * @return ?SignalMapperInterface Current emit mapper, or null when emit fan-out mapping is disabled
     */
    public function getEmitMapper(): ?SignalMapperInterface
    {
        return $this->emitMapper;
    }

    /**
     * Returns table keys declared as affected by a project event.
     *
     * @param string $eventKey Logical project event name
     * @return list<string>
     */
    public function getTableKeysForEvent(string $eventKey): array
    {
        $tableEventRoutes = $this->config['table_event_routes'] ?? [];
        if (!is_array($tableEventRoutes)) {
            return [];
        }

        $routes = $tableEventRoutes[$eventKey] ?? [];
        if (!is_array($routes)) {
            return [];
        }

        $tableKeys = [];
        foreach ($routes as $tableKey) {
            if (is_string($tableKey) && $tableKey !== '') {
                $tableKeys[] = $tableKey;
            }
        }

        return array_values(array_unique($tableKeys));
    }

    /**
     * Accept keys currently subscribed to a page, optionally filtered by a single route param.
     *
     * @return list<string>
     */
    public function getAcceptKeysForPage(string $page, ?string $paramKey = null, ?string $paramValue = null): array
    {
        $keys = [];
        foreach ($this->subscriptionPages as $acceptKey => $subscription) {
            $subPage = $subscription[SignalPayloadConstants::SUBSCRIPTION_PAGE_KEY] ?? '';
            if ($subPage !== $page) {
                continue;
            }
            if ($paramKey === null || $paramValue === null) {
                $keys[] = $acceptKey;
                continue;
            }
            $params = $subscription[SignalPayloadConstants::SUBSCRIPTION_PARAMS_KEY] ?? [];
            $v = $params[$paramKey] ?? null;
            if ((string) $v === $paramValue) {
                $keys[] = $acceptKey;
            }
        }

        return $keys;
    }

    /**
     * Accept keys known to this router's subscription registry.
     *
     * In a worker this is the worker-local mirror used by frontend projections;
     * in the daemon this is the global routing registry used for broadcasts.
     *
     * @return list<string>
     */
    public function getSubscribedAcceptKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->subscriptionPages),
            array_keys($this->subscriptionGroups),
        )));
    }

    /**
     * Get destinations for signal
     *
     * Resolves signal destinations from config. Override in child routers ONLY for
     * dynamic routing that depends on signal content (e.g. extracting agentIndex from payload).
     * All static routing (source -> agent type mapping) must be declared in $config.
     *
     * Design principle: route by sender (signal source + type), not by destination.
     * The signal source and type determine where the signal goes; the destination agent
     * does not pull signals — it receives them based on routing rules.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType?: string, agentIndex?: ?string, acceptKey?: string}>
     *         List of destination configs (agent or websocket)
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

        if (in_array($signalType, [
            SignalTypeConstants::PAGE_SUBSCRIBE,
            SignalTypeConstants::PAGE_UNSUBSCRIBE,
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION,
        ], true)) {
            $destinations = array_merge($destinations, $this->getPageSubscriptionDestinations($signal));
        } elseif ($signalType === SignalTypeConstants::ACTION) {
            $actionDestinations = $this->getActionDestinations($signal);

            if ($actionDestinations !== []) {
                $destinations = array_merge($destinations, $actionDestinations);
            } else {
                $routes = $this->route($signal->signalSource, $signal->signalType, $signal->data);
                foreach ($routes as $route) {
                    $destinations[] = array_merge(['type' => 'agent'], $route);
                }
            }
        } elseif ($signalType === SignalTypeConstants::AGENT_SIGNAL) {
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
     * Get agent destinations for user actions.
     *
     * Uses config['actions'][actionName] -> agentType mapping.
     * Falls back to regular source/type routing when no explicit action mapping is declared.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType: string, agentIndex: null}>
     *         List of agent destination configs
     */
    private function getActionDestinations(SignalDTO $signal): array
    {
        $actionName = $signal->signalName->getName();
        if ($actionName === '') {
            return [];
        }

        $actionRoutes = $this->config['actions'] ?? [];
        if (!is_array($actionRoutes)) {
            return [];
        }

        $agentType = $actionRoutes[$actionName] ?? null;
        if (!is_string($agentType) || $agentType === '') {
            return [];
        }

        return [[
            'type' => 'agent',
            'agentType' => $agentType,
            'agentIndex' => null,
        ]];
    }

    /**
     * Get agent destinations for page subscription signals (subscribe/unsubscribe/update)
     *
     * Uses config['page_subscription_routing'] to resolve per-page agent type.
     * Falls back to config['page_subscription_routing']['default'] when page has no override.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType: string, agentIndex: null}>
     *         List of agent destination configs
     */
    private function getPageSubscriptionDestinations(SignalDTO $signal): array
    {
        $signalType = $signal->signalType->getType();
        $data = $signal->data;

        $page = match ($signalType) {
            SignalTypeConstants::PAGE_SUBSCRIBE => $data instanceof WebSocketPageSubscribeSignalDTO ? $data->page : '',
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => $data instanceof WebSocketPageUpdateSubscriptionSignalDTO ? $data->page : '',
            SignalTypeConstants::PAGE_UNSUBSCRIBE => $signal->signalName->getName(),
            default => '',
        };

        if ($page === '') {
            return [];
        }

        $routingConfig = $this->config['page_subscription_routing'] ?? [];
        $pagesOverrides = $routingConfig['pages'] ?? [];
        $defaultAgentType = $routingConfig['default'] ?? null;

        $agentType = $pagesOverrides[$page] ?? $defaultAgentType;

        if ($agentType === null || $agentType === '') {
            return [];
        }

        return [
            ['type' => 'agent', 'agentType' => $agentType, 'agentIndex' => null],
        ];
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
     * @return list<array{type: string, acceptKey: string}>
     *         List of WebSocket client destination configs
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
     * @return list<array{type: string, agentType: string, agentIndex: ?string}>
     *         List of agent destination configs
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
