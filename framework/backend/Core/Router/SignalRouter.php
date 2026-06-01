<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\API\Router\Exception\GroupSubscriptionNotFoundException;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\BaseDTO;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Sync\DTO\SyncSignalDataKey;
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
 * Extended by project-level routers (e.g. ChatSignalRouter) to declare service-signal
 * defaults and optional dynamic routing.
 *
 * Routing design principle: route by sender, not by destination.
 * Signal source and type determine which agent receives the signal.
 * Agents do not pull signals — they receive them based on routing rules.
 *
 * Service-signal defaults (daemon system bootstrap, generic daemon cron, WebSocket
 * lifecycle) are resolved from protected hooks in the project router subclass.
 *
 * Group subscription ownership is derived from Hilos::getGroupRoutes() at dispatch time.
 *
 * Page subscription, page actions, page-owned non-action signals, and agent-owned
 * agent signals are derived from the active project Hilos facade at dispatch time.
 *
 * For dynamic routing (agentIndex depends on signal content), override getDestinations()
 * in the child router.
 */
class SignalRouter
{
    private const array PAGE_SIGNAL_SOURCES = [
        SignalTypeConstants::AGENT_SIGNAL => SignalSource::AGENT,
        SignalTypeConstants::CRON => SignalSource::DAEMON,
        SignalTypeConstants::FRAME_BINARY => SignalSource::WEBSOCKET,
    ];

    /** @var list<SignalDTO> queued signals to dispatch */
    private array $queuedSignals = [];

    /** @var bool Whether DB sync broadcast is enabled (false for CLI/migrations) */
    public bool $dbSyncBroadcastEnabled = true;

    /** @var SelfBroadcastRegistry DB sync ids this worker broadcast, pending self-apply skip */
    private SelfBroadcastRegistry $dbSelfBroadcast;

    /** @var SelfBroadcastRegistry RT sync ids this worker broadcast, pending self-apply skip */
    private SelfBroadcastRegistry $rtSelfBroadcast;

    /** @var SubscriptionRegistry Worker-local page and group subscription store */
    private SubscriptionRegistry $subscriptions;

    /**
     * Initializes the worker-local subscription mirror and self-broadcast registries.
     */
    public function __construct()
    {
        $this->dbSelfBroadcast = new SelfBroadcastRegistry();
        $this->rtSelfBroadcast = new SelfBroadcastRegistry();
        $this->subscriptions = new SubscriptionRegistry();
    }

    /**
     * Returns the active project facade class for topology registry reads.
     *
     * Project routers override this so framework routing can read project
     * page subscription ownership from the correct Hilos facade.
     *
     * @return class-string<Hilos> Active project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns the fallback owner for page subscriptions to unregistered pages.
     *
     * Return null when unknown pages should not route to an agent.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultPageSubscriptionAgentType(): ?string
    {
        return null;
    }

    /**
     * Returns the fallback owner for group subscriptions to unregistered groups.
     *
     * Return null when unknown groups should not route to an agent.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultGroupSubscriptionAgentType(): ?string
    {
        return null;
    }

    /**
     * Returns agent types started when daemon delivers DAEMON/SYSTEM bootstrap signals.
     *
     * Used for INITIAL_AGENTS_START and other system signals that should wake project
     * agents via WorkerServer::sendSignalToAgent(). Return an empty list when the
     * project starts agents elsewhere.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [];
    }

    /**
     * Returns the fallback owner for generic DAEMON/CRON signals without page ownership.
     *
     * Named cron signals declared on pages are resolved from topology before this fallback.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultDaemonCronAgentType(): ?string
    {
        return null;
    }

    /**
     * Returns the fallback owner for WebSocket lifecycle service signals.
     *
     * Covers HANDSHAKE, CONNECTION_CLOSE, and WEBSOCKET/CRON.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return null;
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

        $routeConfig = $this->resolveServiceSignalRouteConfig($source, $signalTypeValue);
        if ($routeConfig === null) {
            return [];
        }

        return $this->normalizeStaticRoutes($routeConfig);
    }

    /**
     * Resolves service-signal routes from project router protected hooks.
     *
     * @param string $source Signal source identifier
     * @param string $signalTypeValue Signal type value
     * @return string|list<string>|null Route config or null when no route exists
     */
    private function resolveServiceSignalRouteConfig(string $source, string $signalTypeValue): string|array|null
    {
        if ($source === SignalSource::DAEMON && $signalTypeValue === SignalTypeConstants::SYSTEM) {
            $bootstrapAgentTypes = $this->getDefaultSystemBootstrapAgentTypes();

            return $bootstrapAgentTypes !== [] ? $bootstrapAgentTypes : null;
        }

        if ($source === SignalSource::DAEMON && $signalTypeValue === SignalTypeConstants::CRON) {
            $agentType = $this->getDefaultDaemonCronAgentType();

            return is_string($agentType) && $agentType !== '' ? $agentType : null;
        }

        if ($source === SignalSource::WEBSOCKET && in_array($signalTypeValue, [
            SignalTypeConstants::HANDSHAKE,
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CRON,
        ], true)) {
            $agentType = $this->getDefaultWebSocketLifecycleAgentType();

            return is_string($agentType) && $agentType !== '' ? $agentType : null;
        }

        return null;
    }

    /**
     * Normalizes static route config to agent route rows.
     *
     * @param string|list<string> $routeConfig Single agent type or list of agent types
     * @return list<array{agentType: string, agentIndex: null}> Agent route rows with null index
     */
    private function normalizeStaticRoutes(string|array $routeConfig): array
    {
        if (is_string($routeConfig)) {
            return [
                [AgentConstants::FIELD_AGENT_TYPE => $routeConfig, AgentConstants::FIELD_AGENT_INDEX => null],
            ];
        }

        $routes = [];
        foreach ($routeConfig as $agentType) {
            if (is_string($agentType) && $agentType !== '') {
                $routes[] = [AgentConstants::FIELD_AGENT_TYPE => $agentType, AgentConstants::FIELD_AGENT_INDEX => null];
            }
        }

        return $routes;
    }

    // TODO: [signal-destinations-vo] Replace the bare destination shape arrays built here
    // and in webSocketDestination() (and the array{type, agentType?, agentIndex?, acceptKey?}
    // return of getDestinations()) with a typed Destination VO hierarchy
    // (AgentDestination / WebSocketDestination). DaemonManager::dispatchSignals() reads
    // FIELD_TYPE / FIELD_AGENT_TYPE / FIELD_AGENT_INDEX / FIELD_ACCEPT_KEY off these arrays,
    // so this changes the routing contract — go through the contract approval gate first.
    /**
     * Builds an agent destination config row.
     *
     * @param string $agentType Target agent type
     * @param ?string $agentIndex Agent instance index, or null for unindexed agents
     * @return array{type: string, agentType: string, agentIndex: ?string} Agent destination config
     */
    private function agentDestination(string $agentType, ?string $agentIndex = null): array
    {
        return [
            SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT,
            AgentConstants::FIELD_AGENT_TYPE => $agentType,
            AgentConstants::FIELD_AGENT_INDEX => $agentIndex,
        ];
    }

    /**
     * Builds a WebSocket client destination config row.
     *
     * @param string $acceptKey Target client accept key
     * @return array{type: string, acceptKey: string} WebSocket destination config
     */
    private function webSocketDestination(string $acceptKey): array
    {
        return [
            SignalPayloadConstants::FIELD_TYPE => SignalPayloadConstants::DESTINATION_TYPE_WEBSOCKET,
            SignalPayloadConstants::FIELD_ACCEPT_KEY => $acceptKey,
        ];
    }

    /**
     * Resolves static service-signal routes and wraps them as agent destinations.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType: string, agentIndex: ?string}> Agent destination configs
     */
    private function routeToAgentDestinations(SignalDTO $signal): array
    {
        $destinations = [];
        foreach ($this->route($signal->signalSource, $signal->signalType, $signal->data) as $route) {
            $destinations[] = $this->agentDestination(
                $route[AgentConstants::FIELD_AGENT_TYPE],
                $route[AgentConstants::FIELD_AGENT_INDEX],
            );
        }

        return $destinations;
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

        $this->registerSelfBroadcast($this->dbSelfBroadcast, $signalData, SyncSignalDataKey::ID_STRING);

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
        return $this->dbSelfBroadcast->consume($collectionKey, $idString);
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
        $this->registerSelfBroadcast($this->rtSelfBroadcast, $signalData, SyncSignalDataKey::STATE_ID);

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
        return $this->rtSelfBroadcast->consume($collectionKey, $stateId);
    }

    /**
     * Adapts sync signal data to a SelfBroadcastRegistry registration.
     *
     * @param SelfBroadcastRegistry $registry Registry to record the broadcast id in
     * @param SignalDataInterface $signalData Sync signal data carrying collectionKey and the id
     * @param string $idKey Payload key holding the entity id (ID_STRING for DB, STATE_ID for RT)
     */
    private function registerSelfBroadcast(SelfBroadcastRegistry $registry, SignalDataInterface $signalData, string $idKey): void
    {
        $data = $signalData->toArray();
        $registry->register(
            (string) ($data[SyncSignalDataKey::COLLECTION_KEY] ?? ''),
            (string) ($data[$idKey] ?? ''),
        );
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
        if ($this->queuedSignals === []) {
            return null;
        }

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
        $this->subscriptions->subscribeToPage($data->acceptKey, $page, $data->params);
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
        $this->subscriptions->updatePageSubscription($data->acceptKey, $page, $data->params);
    }

    /**
     * Subscribe user to group.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupSubscribeSignalDTO $data Subscribe signal (acceptKey, params)
     */
    public function subscribeToGroup(string $group, WebSocketGroupSubscribeSignalDTO $data): void
    {
        $this->subscriptions->subscribeToGroup($data->acceptKey, $group, $data->params);
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
        $this->subscriptions->updateGroupSubscription($data->acceptKey, $group, $data->params);
    }


    /**
     * Unsubscribe user from page.
     *
     * @param string $page Page identifier
     * @param WebSocketPageUnsubscribeSignalDTO $data Unsubscribe signal (acceptKey)
     */
    public function unsubscribeFromPage(string $page, WebSocketPageUnsubscribeSignalDTO $data): void
    {
        $this->subscriptions->unsubscribeFromPage($data->acceptKey, $page);
    }

    /**
     * Unsubscribe user from group.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupUnsubscribeSignalDTO $data Unsubscribe signal (acceptKey)
     */
    public function unsubscribeFromGroup(string $group, WebSocketGroupUnsubscribeSignalDTO $data): void
    {
        $this->subscriptions->unsubscribeFromGroup($data->acceptKey, $group);
    }


    /**
     * Unsubscribe user from all subscriptions
     *
     * @param string $acceptKey Accept key identifier
     */
    public function unsubscribeFromAll(string $acceptKey): void
    {
        $this->subscriptions->unsubscribeFromAll($acceptKey);
    }

    /**
     * Accept keys currently subscribed to a page, optionally filtered by a single route param.
     *
     * @param string $page Page identifier to match subscriptions against
     * @param ?string $paramKey Route param key to filter on, or null for no filter
     * @param ?string $paramValue Expected route param value (compared as string), or null for no filter
     * @return list<string> Accept keys subscribed to the page
     */
    public function getAcceptKeysForPage(string $page, ?string $paramKey = null, ?string $paramValue = null): array
    {
        return $this->subscriptions->getAcceptKeysForPage($page, $paramKey, $paramValue);
    }

    /**
     * Page subscriptions currently mirrored in this router.
     *
     * Browser context uses this worker-local mirror to send page-shaped
     * signals only to connections that are subscribed in the current worker.
     *
     * @return array<string, array{page: string, params: array<string, string>}> Subscriptions keyed by accept key
     */
    public function getPageSubscriptions(): array
    {
        return $this->subscriptions->getPageSubscriptions();
    }

    /**
     * Accept keys known to this router's subscription registry.
     *
     * In a worker this is the worker-local mirror used by browser
     * fan-out; in the daemon this is the global routing registry used for
     * broadcasts.
     *
     * @return list<string> Unique accept keys from page and group subscriptions
     */
    public function getSubscribedAcceptKeys(): array
    {
        return $this->subscriptions->getSubscribedAcceptKeys();
    }

    /**
     * Get destinations for signal
     *
     * Resolves signal destinations from project topology and service-signal hooks.
     *
     * Composition is ADDITIVE: a single signal may fan out to any combination of
     * destination kinds (WebSocket clients and/or agents). Each contributor below
     * inspects the signal independently and returns zero or more destinations; the
     * results are merged and de-duplicated. This is intentionally not an exclusive
     * switch — nothing caps a signal at a single destination category. Contributors
     * self-gate on signal type/source, so adding one never leaks routes to types it
     * does not own.
     *
     * Projects add their own contributors by overriding additionalDestinations();
     * override this method only for routing the topology registry cannot express.
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
        $destinations = [
            ...$this->getWebSocketDestinations($signal),
            ...$this->getPageSubscriptionDestinations($signal),
            ...$this->getGroupSubscriptionDestinations($signal),
            ...$this->getActionDestinations($signal),
            ...$this->getAgentDestinations($signal),
            ...$this->getPageOwnedSignalDestinations($signal),
            ...$this->additionalDestinations($signal),
        ];

        return $this->dedupeDestinations($destinations);
    }

    /**
     * Project hook for additional destination contributors.
     *
     * Override to fan a signal out to extra agent or WebSocket destinations that the
     * topology registry cannot express. Returned destinations are merged with the
     * framework contributors and de-duplicated.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType?: string, agentIndex?: ?string, acceptKey?: string}>
     *         Extra destination configs
     */
    protected function additionalDestinations(SignalDTO $signal): array
    {
        return [];
    }

    /**
     * Resolve agent destination for page-owned non-action signals, falling back to
     * service-signal defaults when no page owns the signal.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType: string, agentIndex: ?string}> Agent destination configs
     */
    private function getPageOwnedSignalDestinations(SignalDTO $signal): array
    {
        $pageSignalDestinations = $this->getPageSignalDestinations($signal);

        return $pageSignalDestinations !== []
            ? $pageSignalDestinations
            : $this->routeToAgentDestinations($signal);
    }

    /**
     * Removes duplicate destinations produced by overlapping contributors.
     *
     * Two contributors can legitimately resolve the same page-owned signal to the
     * same agent; a signal must never be delivered to one destination twice.
     *
     * @param list<array{type: string, agentType?: string, agentIndex?: ?string, acceptKey?: string}> $destinations
     * @return list<array{type: string, agentType?: string, agentIndex?: ?string, acceptKey?: string}> Unique destinations
     */
    private function dedupeDestinations(array $destinations): array
    {
        $seen = [];
        $unique = [];
        foreach ($destinations as $destination) {
            $key = ($destination[SignalPayloadConstants::FIELD_TYPE] ?? '')
                . '|' . ($destination[AgentConstants::FIELD_AGENT_TYPE] ?? '')
                . '|' . ($destination[AgentConstants::FIELD_AGENT_INDEX] ?? '')
                . '|' . ($destination[SignalPayloadConstants::FIELD_ACCEPT_KEY] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $destination;
        }

        return $unique;
    }

    /**
     * Get agent destinations for user actions.
     *
     * Uses page ACTIONS ownership through the active project topology.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType: string, agentIndex: null}>
     *         List of agent destination configs
     */
    private function getActionDestinations(SignalDTO $signal): array
    {
        if ($signal->signalType->getType() !== SignalTypeConstants::ACTION) {
            return [];
        }

        $actionName = $signal->signalName->getName();
        if ($actionName === '') {
            return [];
        }

        if ($signal->signalSource->getSource() !== SignalSource::WEBSOCKET) {
            return [];
        }

        $agentType = $this->hilosClass()::getActionAgentRoutes()[$actionName] ?? null;
        if (!is_string($agentType) || $agentType === '') {
            return [];
        }

        return [$this->agentDestination($agentType)];
    }

    /**
     * Get agent destinations for page subscription signals (subscribe/unsubscribe/update)
     *
     * Uses the active project topology to resolve per-page agent type.
     * Falls back to getDefaultPageSubscriptionAgentType() for unregistered pages.
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

        $agentType = $this->getPageSubscriptionAgentType($page);
        if ($agentType === null) {
            return [];
        }

        return [$this->agentDestination($agentType)];
    }

    /**
     * Resolves page subscription owner from project topology.
     *
     * @param string $page Page name from the subscription signal
     * @return ?string Agent type or null when no route exists
     */
    private function getPageSubscriptionAgentType(string $page): ?string
    {
        $hilosClass = $this->hilosClass();
        $agentType = $hilosClass::getPageRoutes()[$page] ?? null;
        if (is_string($agentType) && $agentType !== '') {
            return $agentType;
        }

        $fallbackAgentType = $this->getDefaultPageSubscriptionAgentType();

        return is_string($fallbackAgentType) && $fallbackAgentType !== ''
            ? $fallbackAgentType
            : null;
    }

    /**
     * Get agent destinations for group subscription signals (subscribe/unsubscribe/update).
     *
     * Uses the active project topology to resolve per-group agent type.
     * Falls back to getDefaultGroupSubscriptionAgentType() for unregistered groups.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType: string, agentIndex: null}>
     *         List of agent destination configs
     */
    private function getGroupSubscriptionDestinations(SignalDTO $signal): array
    {
        $signalType = $signal->signalType->getType();
        $data = $signal->data;

        $group = match ($signalType) {
            SignalTypeConstants::GROUP_SUBSCRIBE => $data instanceof WebSocketGroupSubscribeSignalDTO ? $data->group : '',
            SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => $data instanceof WebSocketGroupUpdateSubscriptionSignalDTO ? $data->group : '',
            SignalTypeConstants::GROUP_UNSUBSCRIBE => $data instanceof WebSocketGroupUnsubscribeSignalDTO
                ? $data->group
                : $signal->signalName->getName(),
            default => '',
        };

        if ($group === '') {
            return [];
        }

        $agentType = $this->getGroupSubscriptionAgentType($group);
        if ($agentType === null) {
            return [];
        }

        return [$this->agentDestination($agentType)];
    }

    /**
     * Resolves group subscription owner from project topology.
     *
     * @param string $group Group name from the subscription signal
     * @return ?string Agent type or null when no route exists
     */
    private function getGroupSubscriptionAgentType(string $group): ?string
    {
        $hilosClass = $this->hilosClass();
        $agentType = $hilosClass::getGroupRoutes()[$group] ?? null;
        if (is_string($agentType) && $agentType !== '') {
            return $agentType;
        }

        $fallbackAgentType = $this->getDefaultGroupSubscriptionAgentType();

        return is_string($fallbackAgentType) && $fallbackAgentType !== ''
            ? $fallbackAgentType
            : null;
    }

    /**
     * Get agent destinations for page-owned non-action signals.
     *
     * Uses page SIGNALS declarations and page SUBSCRIPTION_AGENT_TYPE owners
     * through the active project topology.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType: string, agentIndex: null}>
     *         List of agent destination configs
     */
    private function getPageSignalDestinations(SignalDTO $signal): array
    {
        $agentType = $this->getPageSignalAgentType($signal);
        if ($agentType === null) {
            return [];
        }

        return [$this->agentDestination($agentType)];
    }

    /**
     * Resolve page-owned signal owner from project topology.
     *
     * @param SignalDTO $signal Signal DTO
     * @return ?string Agent type or null when no page-owned route exists
     */
    private function getPageSignalAgentType(SignalDTO $signal): ?string
    {
        $signalType = $signal->signalType->getType();
        $expectedSource = self::PAGE_SIGNAL_SOURCES[$signalType] ?? null;
        if ($expectedSource === null || $signal->signalSource->getSource() !== $expectedSource) {
            return null;
        }

        $hilosClass = $this->hilosClass();
        $route = $hilosClass::getPageSignalAgentRoutes()[$signalType] ?? null;
        if (is_string($route) && $route !== '') {
            return $route;
        }

        if (!is_array($route)) {
            return null;
        }

        $agentType = $route[$signal->signalName->getName()] ?? null;

        return is_string($agentType) && $agentType !== ''
            ? $agentType
            : null;
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
                    $destinations[] = $this->webSocketDestination($targetAcceptKey);
                }
                break;

            case SignalTypeConstants::WS_ALL:
                // Return all subscribed clients, excluding excludeAcceptKey
                foreach ($this->subscriptions->pageAcceptKeys() as $acceptKey) {
                    if ($excludeAcceptKey !== null && $acceptKey === $excludeAcceptKey) {
                        continue;
                    }
                    $destinations[] = $this->webSocketDestination($acceptKey);
                }
                break;

            case SignalTypeConstants::WS_GROUP:
                // Return clients subscribed to targetGroup, excluding excludeAcceptKey
                if ($targetGroup !== null && $targetGroup !== '') {
                    foreach ($this->subscriptions->acceptKeysForGroup($targetGroup) as $acceptKey) {
                        if ($excludeAcceptKey !== null && $acceptKey === $excludeAcceptKey) {
                            continue;
                        }
                        $destinations[] = $this->webSocketDestination($acceptKey);
                    }
                }
                break;
        }

        return $destinations;
    }

    /**
     * Get agent destinations for agent-to-agent signal.
     *
     * Uses page-owned signal topology first, then agent AGENT_SIGNALS ownership
     * through the active project topology.
     *
     * For indexed multi-instance agents, reads the index field declared in
     * AGENT_SIGNALS config via getAgentSignalIndexFields() and extracts the
     * value from the inner payload's toArray(). Positive int and non-empty
     * string values are both accepted as agent index. Absent or invalid values
     * produce no destination and a warning is logged.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<array{type: string, agentType: string, agentIndex: ?string}>
     *         List of agent destination configs
     */
    private function getAgentDestinations(SignalDTO $signal): array
    {
        if ($signal->signalType->getType() !== SignalTypeConstants::AGENT_SIGNAL) {
            return [];
        }

        $pageSignalDestinations = $this->getPageSignalDestinations($signal);
        if ($pageSignalDestinations !== []) {
            return $pageSignalDestinations;
        }

        if ($signal->signalSource->getSource() !== SignalSource::AGENT) {
            return [];
        }

        $signalName = $signal->signalName->getName();
        $hilosClass = $this->hilosClass();
        $agentType = $hilosClass::getAgentSignalRoutes()[$signalName] ?? null;
        if (!is_string($agentType) || $agentType === '') {
            return [];
        }

        $agentIndex = null;
        $indexField = $hilosClass::getAgentSignalIndexFields()[$signalName] ?? null;
        if (is_string($indexField) && $indexField !== '') {
            $payload = $signal->data instanceof AgentSignalData ? $signal->data->data : null;
            $value = $payload?->toArray()[$indexField] ?? null;
            if (is_int($value) && $value > 0) {
                $agentIndex = (string) $value;
            } elseif (is_string($value) && $value !== '') {
                $agentIndex = $value;
            } else {
                Logger::error("Indexed agent signal {$signalName} payload missing or invalid field '{$indexField}'");
                return [];
            }
        }

        return [$this->agentDestination($agentType, $agentIndex)];
    }

    /**
     * Creates agent-owned signal inner payload DTO from project topology.
     *
     * @param string $signalName Signal name
     * @param AgentSignalData $signalData Wrapped agent signal payload
     * @return AgentSignalData Validated or passthrough wrapper
     * @throws InvalidAgentSignalPayloadException When topology declares a DTO and payload does not match
     */
    public function createAgentSignalPayloadDTO(string $signalName, AgentSignalData $signalData): AgentSignalData
    {
        $dtoClass = $this->hilosClass()::getAgentSignalDtoRoutes()[$signalName] ?? null;
        if (!is_string($dtoClass) || !is_subclass_of($dtoClass, SignalDataInterface::class)) {
            return $signalData;
        }

        $payload = $signalData->data;
        if ($payload instanceof $dtoClass) {
            return $signalData;
        }

        $dataArray = $payload instanceof BaseDTO ? $payload->toArray() : [];

        try {
            $parsed = $dtoClass::fromArray($dataArray);
        } catch (\Throwable) {
            throw new InvalidAgentSignalPayloadException($signalName, $dtoClass, $payload);
        }

        if (!$parsed instanceof $dtoClass) {
            throw new InvalidAgentSignalPayloadException($signalName, $dtoClass, $payload);
        }

        return new AgentSignalData($parsed);
    }
}
