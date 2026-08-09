<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\API\Router\Exception\GroupSubscriptionNotFoundException;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\BrokenSignalPayloadDtoException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\AllClientsDestination;
use Hilos\Core\Router\Destination\CommandReplyDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Logger;
use SplQueue;

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

    /** @var int Byte length of the random per-process emitter identity */
    private const int EMITTER_RANDOM_BYTES = 8;

    /** @var string Separates the parts of a destination dedupe key; never appears inside a part */
    private const string DESTINATION_KEY_SEPARATOR = '|';

    /** @var SplQueue<SignalDTO> Queued signals awaiting dispatch (FIFO, O(1) enqueue/dequeue) */
    private SplQueue $queuedSignals;

    /** @var bool Whether DB sync broadcast is enabled (false for CLI/migrations) */
    public bool $dbSyncBroadcastEnabled = true;

    /** @var SelfBroadcastRegistry DB sync ids this worker broadcast, pending self-apply skip */
    private SelfBroadcastRegistry $dbSelfBroadcast;

    /** @var SelfBroadcastRegistry RT sync ids this worker broadcast, pending self-apply skip */
    private SelfBroadcastRegistry $rtSelfBroadcast;

    /** @var SubscriptionRegistry Worker-local page and group subscription store */
    private SubscriptionRegistry $subscriptions;

    /**
     * @var string Identity of this process as the emitter of collection-scoped syncs.
     *
     * A clear fact has no row id, so the self-broadcast registry cannot key it the way
     * row syncs are keyed. The emitter identity replaces that registration entirely:
     * it travels in the payload and is compared on receive, so suppression holds no
     * state that could be evicted. Random rather than the worker index because indexes
     * are reused after a worker restart, and an echo from the dead worker would then
     * suppress a legitimate clear in its successor.
     */
    private readonly string $emitter;

    /**
     * Initializes the signal queue, worker-local subscription mirror, self-broadcast
     * registries, and this process's emitter identity.
     */
    public function __construct()
    {
        $this->queuedSignals = new SplQueue();
        $this->dbSelfBroadcast = new SelfBroadcastRegistry();
        $this->rtSelfBroadcast = new SelfBroadcastRegistry();
        $this->subscriptions = new SubscriptionRegistry();
        $this->emitter = RandomHelper::hex(self::EMITTER_RANDOM_BYTES);
    }

    /**
     * Returns the emitter identity stamped on collection-scoped syncs this process sends.
     *
     * @return string Emitter identity of this process
     */
    public function getEmitter(): string
    {
        return $this->emitter;
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
     * Resolves service-signal owner agent types from the project router protected hooks.
     *
     * Covers DAEMON/SYSTEM bootstrap, generic DAEMON/CRON, and WebSocket lifecycle
     * (HANDSHAKE, CONNECTION_CLOSE, WEBSOCKET/CRON). Returns an empty list for any
     * signal these hooks do not own.
     *
     * @param string $source Signal source identifier
     * @param string $signalTypeValue Signal type value
     * @return list<string> Owner agent types, empty when no service-signal route exists
     */
    private function resolveServiceSignalAgentTypes(string $source, string $signalTypeValue): array
    {
        if ($source === SignalSource::DAEMON && $signalTypeValue === SignalTypeConstants::SYSTEM) {
            return $this->nonEmptyAgentTypes($this->getDefaultSystemBootstrapAgentTypes());
        }

        if ($source === SignalSource::DAEMON && $signalTypeValue === SignalTypeConstants::CRON) {
            return $this->nonEmptyAgentTypes([$this->getDefaultDaemonCronAgentType()]);
        }

        if ($source === SignalSource::WEBSOCKET && in_array($signalTypeValue, [
            SignalTypeConstants::HANDSHAKE,
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CRON,
        ], true)) {
            return $this->nonEmptyAgentTypes([$this->getDefaultWebSocketLifecycleAgentType()]);
        }

        return [];
    }

    /**
     * Filters raw project-hook agent types down to non-empty string entries.
     *
     * @param list<?string> $agentTypes Raw agent types from project hooks
     * @return list<string> Non-empty agent types
     */
    private function nonEmptyAgentTypes(array $agentTypes): array
    {
        $result = [];
        foreach ($agentTypes as $agentType) {
            if (is_string($agentType) && $agentType !== '') {
                $result[] = $agentType;
            }
        }

        return $result;
    }

    /**
     * Resolves service-signal routes and wraps them as agent destinations.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<AgentDestination> Agent destinations, empty when no service-signal route exists
     */
    private function routeToAgentDestinations(SignalDTO $signal): array
    {
        return array_map(
            static fn (string $agentType): AgentDestination => new AgentDestination($agentType),
            $this->resolveServiceSignalAgentTypes(
                $signal->signalSource->getSource(),
                $signal->signalType->getType(),
            ),
        );
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
     * @throws InvalidArgumentException When the signal name is empty
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
        $this->queuedSignals->enqueue($signal);
    }

    /**
     * Queue DB sync signal (from Object_::sync/delete).
     * Skips if broadcast disabled. Registers (collectionKey, idString) for self-apply
     * skip when the payload names both; an unnamed one is broadcast without the
     * self-apply guard, exactly as before, because a payload that cannot be deduped
     * still has to reach the other processes.
     *
     * @param string $signalName Signal name (e.g. SignalConstants::DB_SYNC_CREATED)
     * @param DbSyncSignalDataInterface $signalData Signal data with collectionKey and idString
     */
    public function queueDbSyncSignal(string $signalName, DbSyncSignalDataInterface $signalData): void
    {
        if (!$this->dbSyncBroadcastEnabled) {
            return;
        }

        if ($signalData->collectionKey !== '' && $signalData->idString !== '') {
            $this->dbSelfBroadcast->register($signalData->collectionKey, $signalData->idString);
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
        return $this->dbSelfBroadcast->consume($collectionKey, $idString);
    }

    /**
     * Queue a DB sync cleared signal (collection-scoped truncate).
     * Skips if broadcast disabled. Stamps the payload with this process's emitter identity.
     *
     * @param DbSyncClearedSignalData $signalData Cleared signal data with collectionKey
     */
    public function queueDbSyncClearedSignal(DbSyncClearedSignalData $signalData): void
    {
        if (!$this->dbSyncBroadcastEnabled || $signalData->collectionKey === '') {
            return;
        }

        $this->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_SYNC_CLEARED),
            signalName: new SignalName(SignalTypeConstants::DB_SYNC_CLEARED),
            signalData: $signalData->withEmitter($this->emitter),
        );
    }

    /**
     * Check if a clear apply should be skipped because this process emitted it.
     *
     * An unstamped clear counts as someone else's: skipping a foreign truncate is more
     * dangerous than applying an extra one, because rows left standing after a remote
     * delete collide with freshly minted ids.
     *
     * @param ?string $emitter Emitter identity from the clear payload, null when unstamped
     * @return bool True if this was our broadcast, skip apply
     */
    public function shouldSkipDbSyncClearApply(?string $emitter): bool
    {
        return $emitter !== null && $emitter === $this->emitter;
    }

    /**
     * Queue RT sync signal (from RtActions write operations).
     * Registers (collectionKey, stateId) for self-apply skip when the payload names
     * both; an unnamed one is broadcast without the self-apply guard, exactly as
     * before, because a payload that cannot be deduped still has to reach the other
     * processes.
     *
     * @param string $signalName Signal name (e.g. SignalConstants::RT_SYNC_CREATED)
     * @param RtSyncSignalDataInterface $signalData Signal data with collectionKey and stateId
     */
    public function queueRtSyncSignal(string $signalName, RtSyncSignalDataInterface $signalData): void
    {
        if ($signalData->collectionKey !== '' && $signalData->stateId !== '') {
            $this->rtSelfBroadcast->register($signalData->collectionKey, $signalData->stateId);
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
        return $this->rtSelfBroadcast->consume($collectionKey, $stateId);
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
        return $this->queuedSignals->isEmpty() ? null : $this->queuedSignals->dequeue();
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
     * Stores or replaces a connection's viewport for one table.
     *
     * @param string $acceptKey Client accept key
     * @param TableViewportSubscription $viewport Table viewport descriptor
     */
    public function setTableViewport(string $acceptKey, TableViewportSubscription $viewport): void
    {
        $this->subscriptions->setTableViewport($acceptKey, $viewport);
    }

    /**
     * Returns a connection's viewport for one table, or null when not set.
     *
     * @param string $acceptKey Client accept key
     * @param string $tableKey Table key
     * @return ?TableViewportSubscription Stored viewport or null
     */
    public function getTableViewport(string $acceptKey, string $tableKey): ?TableViewportSubscription
    {
        return $this->subscriptions->getTableViewport($acceptKey, $tableKey);
    }

    /**
     * Returns all table viewports held by a connection, keyed by table key.
     *
     * @param string $acceptKey Client accept key
     * @return array<string, TableViewportSubscription> Viewports keyed by table key
     */
    public function getTableViewports(string $acceptKey): array
    {
        return $this->subscriptions->getTableViewports($acceptKey);
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
     * @return list<Destination> Resolved delivery targets (agent and/or WebSocket), de-duplicated
     */
    public function getDestinations(SignalDTO $signal): array
    {
        $destinations = [
            ...$this->getWebSocketDestinations($signal),
            ...$this->getPageSubscriptionDestinations($signal),
            ...$this->getGroupSubscriptionDestinations($signal),
            ...$this->getActionDestinations($signal),
            ...$this->getCommandDestinations($signal),
            ...$this->getAgentDestinations($signal),
            ...$this->getPageOwnedSignalDestinations($signal),
            ...$this->additionalDestinations($signal),
        ];

        return $this->applyPlacement($this->dedupeDestinations($destinations));
    }

    /**
     * Rewrites agent destinations that resolve to another node into remote destinations.
     *
     * Cross-node routing preserves the declarative route-by-sender model: destinations are
     * resolved exactly as before, then this post-pass asks the placement lookup where each
     * agent lives. An agent on another node becomes a {@see RemoteAgentDestination} the
     * daemon forwards over the peer channel; a local agent (or any target when the lookup
     * reports null) stays an {@see AgentDestination} and is delivered locally. Off-cluster,
     * or on a node with no registered lookup, there is no lookup and the list is returned
     * untouched, so single-node behaviour is unchanged. Only {@see AgentDestination} is
     * eligible — WebSocket, all-clients, and command-reply targets are bound to this node.
     *
     * @param list<Destination> $destinations Resolved destinations before placement
     * @return list<Destination> Destinations with cross-node agents rewritten to remote
     */
    private function applyPlacement(array $destinations): array
    {
        $placement = Hilos::$cluster?->workerPlacement();
        if ($placement === null) {
            return $destinations;
        }

        foreach ($destinations as $index => $destination) {
            if (!$destination instanceof AgentDestination) {
                continue;
            }

            $nodeId = $placement->nodeFor($destination->agentType, $destination->agentIndex);
            if ($nodeId !== null) {
                $destinations[$index] = new RemoteAgentDestination($nodeId, $destination->agentType, $destination->agentIndex);
            }
        }

        return $destinations;
    }

    /**
     * Project hook for additional destination contributors.
     *
     * Override to fan a signal out to extra agent or WebSocket destinations that the
     * topology registry cannot express. Returned destinations are merged with the
     * framework contributors and de-duplicated.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<Destination> Extra delivery targets merged with the framework contributors
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
     * @return list<AgentDestination> Agent destinations for the page-owned signal, or the service-signal fallback
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
     * Destination types the framework does not know are keyed by object identity,
     * so a project's custom destinations are never collapsed.
     *
     * @param list<Destination> $destinations Destinations from all contributors
     * @return list<Destination> Unique destinations
     */
    private function dedupeDestinations(array $destinations): array
    {
        if (count($destinations) < 2) {
            return $destinations;
        }

        $seen = [];
        $unique = [];
        foreach ($destinations as $destination) {
            $keyParts = match (true) {
                $destination instanceof AgentDestination =>
                    [AgentDestination::class, $destination->agentType, $destination->agentIndex],
                $destination instanceof WebSocketDestination =>
                    [WebSocketDestination::class, $destination->acceptKey],
                $destination instanceof AllClientsDestination =>
                    [AllClientsDestination::class, $destination->excludeAcceptKey],
                default => [(string) spl_object_id($destination)],
            };
            // A part that is absent contributes nothing to the key rather than an
            // empty segment: the key is built from typed fields, not from strings
            // that had to stand in for a missing one.
            $key = implode(self::DESTINATION_KEY_SEPARATOR, array_filter(
                $keyParts,
                static fn(string|int|null $part): bool => $part !== null,
            ));
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
     * Uses page ACTIONS ownership through the active project topology, then falls
     * back to agent-owned AGENT_ACTIONS ownership for page-independent actions.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<AgentDestination> Single agent destination, or empty when no action route exists
     */
    private function getActionDestinations(SignalDTO $signal): array
    {
        if ($signal->signalType->getType() !== SignalTypeConstants::ACTION) {
            return [];
        }

        if ($signal->signalSource->getSource() !== SignalSource::WEBSOCKET) {
            return [];
        }

        $actionName = $signal->signalName->getName();
        $agentType = $this->hilosClass()::getActionAgentRoutes()[$actionName]
            ?? $this->hilosClass()::getAgentActionRoutes()[$actionName]
            ?? null;
        if (!is_string($agentType) || $agentType === '') {
            return [];
        }

        return [new AgentDestination($agentType)];
    }

    /**
     * Get destinations for CLI command signals.
     *
     * COMMAND_REQUEST routes to the agent that owns the command name through the
     * project getCommandAgentRoutes() map. COMMAND_REPLY routes back to the held
     * CLI connection, addressed by the reply's correlation id.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<Destination> Command destinations, or empty when unrouted
     */
    private function getCommandDestinations(SignalDTO $signal): array
    {
        $signalType = $signal->signalType->getType();
        $signalData = $signal->data;

        if ($signalType === SignalTypeConstants::COMMAND_REQUEST && $signalData instanceof CommandRequestDTO) {
            $agentType = $this->hilosClass()::getCommandAgentRoutes()[$signalData->command] ?? null;
            if (!is_string($agentType) || $agentType === '') {
                return [];
            }

            return [new AgentDestination($agentType)];
        }

        if ($signalType === SignalTypeConstants::COMMAND_REPLY && $signalData instanceof CommandReplyDTO) {
            if ($signalData->correlationId === '') {
                return [];
            }

            return [new CommandReplyDestination($signalData->correlationId)];
        }

        return [];
    }

    /**
     * Get agent destinations for page subscription signals (subscribe/unsubscribe/update)
     *
     * Uses the active project topology to resolve per-page agent type.
     * Falls back to getDefaultPageSubscriptionAgentType() for unregistered pages.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<AgentDestination> Single agent destination, or empty when no page route exists
     */
    private function getPageSubscriptionDestinations(SignalDTO $signal): array
    {
        $signalType = $signal->signalType->getType();
        $data = $signal->data;

        $page = match ($signalType) {
            SignalTypeConstants::PAGE_SUBSCRIBE => $data instanceof WebSocketPageSubscribeSignalDTO ? $data->page : null,
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => $data instanceof WebSocketPageUpdateSubscriptionSignalDTO ? $data->page : null,
            SignalTypeConstants::TABLE_VIEWPORT => $data instanceof WebSocketTableViewportSignalDTO ? $data->page : null,
            // Unsubscribe carries the page in the signal name, which SignalDTO
            // guarantees is non-empty.
            SignalTypeConstants::PAGE_UNSUBSCRIBE => $signal->signalName->getName(),
            default => null,
        };

        if ($page === null) {
            return [];
        }

        $agentType = $this->getPageSubscriptionAgentType($page);
        if ($agentType === null) {
            return [];
        }

        return [new AgentDestination($agentType)];
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
     * @return list<AgentDestination> Single agent destination, or empty when no group route exists
     */
    private function getGroupSubscriptionDestinations(SignalDTO $signal): array
    {
        $signalType = $signal->signalType->getType();
        $data = $signal->data;

        $group = match ($signalType) {
            SignalTypeConstants::GROUP_SUBSCRIBE => $data instanceof WebSocketGroupSubscribeSignalDTO ? $data->group : null,
            SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => $data instanceof WebSocketGroupUpdateSubscriptionSignalDTO ? $data->group : null,
            // Falling back to the name is safe: SignalDTO guarantees it is non-empty.
            SignalTypeConstants::GROUP_UNSUBSCRIBE => $data instanceof WebSocketGroupUnsubscribeSignalDTO
                ? $data->group
                : $signal->signalName->getName(),
            default => null,
        };

        if ($group === null) {
            return [];
        }

        $agentType = $this->getGroupSubscriptionAgentType($group);
        if ($agentType === null) {
            return [];
        }

        return [new AgentDestination($agentType)];
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
     * @return list<AgentDestination> Single agent destination, or empty when no page-owned signal route exists
     */
    private function getPageSignalDestinations(SignalDTO $signal): array
    {
        $agentType = $this->getPageSignalAgentType($signal);
        if ($agentType === null) {
            return [];
        }

        return [new AgentDestination($agentType)];
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
     * For ws_all: returns all page-subscribed clients, excluding excludeAcceptKey
     * For ws_all_connected: returns a single all-clients broadcast marker, excluding excludeAcceptKey
     * For ws_group: returns clients subscribed to targetGroup, excluding excludeAcceptKey
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<Destination> WebSocket client destinations, or a single all-clients broadcast marker
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
                if ($targetAcceptKey !== null) {
                    $destinations[] = new WebSocketDestination($targetAcceptKey);
                }
                break;

            case SignalTypeConstants::WS_ALL:
                // Return all subscribed clients, excluding excludeAcceptKey
                foreach ($this->subscriptions->pageAcceptKeys() as $acceptKey) {
                    if ($excludeAcceptKey !== null && $acceptKey === $excludeAcceptKey) {
                        continue;
                    }
                    $destinations[] = new WebSocketDestination($acceptKey);
                }
                break;

            case SignalTypeConstants::WS_ALL_CONNECTED:
                // Single broadcast marker; daemon fans out to all connected clients
                $destinations[] = new AllClientsDestination($excludeAcceptKey);
                break;

            case SignalTypeConstants::WS_GROUP:
                // Return clients subscribed to targetGroup, excluding excludeAcceptKey
                if ($targetGroup !== null) {
                    foreach ($this->subscriptions->acceptKeysForGroup($targetGroup) as $acceptKey) {
                        if ($excludeAcceptKey !== null && $acceptKey === $excludeAcceptKey) {
                            continue;
                        }
                        $destinations[] = new WebSocketDestination($acceptKey);
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
     * @return list<AgentDestination> Single agent destination (optionally indexed), or empty when unrouted
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

        return [new AgentDestination($agentType, $agentIndex)];
    }

    /**
     * Creates agent-owned signal inner payload DTO from project topology.
     *
     * @param string $signalName Signal name
     * @param AgentSignalData $signalData Wrapped agent signal payload
     * @return AgentSignalData Validated or passthrough wrapper
     * @throws BrokenSignalPayloadDtoException When topology declares a class that cannot be hydrated at all
     * @throws InvalidAgentSignalPayloadException When topology declares a DTO and payload does not match
     */
    public function createAgentSignalPayloadDTO(string $signalName, AgentSignalData $signalData): AgentSignalData
    {
        // Registry values are class-strings; anything else means the signal declares no DTO.
        $dtoClass = $this->hilosClass()::getAgentSignalDtoRoutes()[$signalName] ?? null;
        if (!is_string($dtoClass)) {
            return $signalData;
        }

        $payload = $signalData->data;
        if ($payload instanceof $dtoClass) {
            return $signalData;
        }

        try {
            $parsed = SignalPayloadHydrator::hydrate($payload->toArray(), $dtoClass, $signalName);
        } catch (BrokenSignalPayloadDtoException $e) {
            // A broken registry entry is not a payload problem; it must not be reported as one.
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidAgentSignalPayloadException($signalName, $dtoClass, $payload, $e);
        }

        return new AgentSignalData($parsed);
    }

    /**
     * Creates the topology-hydrated command request from project topology.
     *
     * When the command declares an inner payload DTO through getCommandDtoRoutes(),
     * hydrates it from the raw payload and returns a new CommandRequestDTO carrying
     * the parsed DTO in its transient parsedPayload slot. When no DTO is declared,
     * returns the request unchanged.
     *
     * @param string $command Command name
     * @param CommandRequestDTO $data Incoming command request
     * @return CommandRequestDTO Hydrated or passthrough command request
     * @throws BrokenSignalPayloadDtoException When topology declares a class that cannot be hydrated at all
     * @throws InvalidCommandPayloadException When topology declares a DTO and the payload does not match
     */
    public function createCommandPayloadDTO(string $command, CommandRequestDTO $data): CommandRequestDTO
    {
        // Registry values are class-strings; anything else means the command declares no DTO.
        $dtoClass = $this->hilosClass()::getCommandDtoRoutes()[$command] ?? null;
        if (!is_string($dtoClass)) {
            return $data;
        }

        try {
            $parsed = SignalPayloadHydrator::hydrate($data->payload, $dtoClass, $command);
        } catch (BrokenSignalPayloadDtoException $e) {
            // A broken registry entry is not a payload problem; it must not be reported as one.
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidCommandPayloadException($command, $dtoClass, $data->payload, $e);
        }

        return new CommandRequestDTO(
            correlationId: $data->correlationId,
            command: $data->command,
            payload: $data->payload,
            parsedPayload: $parsed,
        );
    }

    /**
     * Creates the typed payload DTO for a client action owned by the given agent.
     *
     * Returns null when the action is not owned by that agent through AGENT_ACTIONS,
     * so the worker keeps a page-owned action on the page-router dispatch path.
     *
     * @param string $action Action name from the WebSocket frame
     * @param array<string, mixed> $data Raw action payload
     * @param string $agentType Agent type the action was routed to
     * @return ?ActionPayloadDTO Parsed payload, or null when the action is not agent-owned by this agent
     */
    public function createAgentActionPayloadDTO(string $action, array $data, string $agentType): ?ActionPayloadDTO
    {
        if (($this->hilosClass()::getAgentActionRoutes()[$action] ?? null) !== $agentType) {
            return null;
        }

        $dtoClass = $this->hilosClass()::getAgentActionDtoRoutes()[$action] ?? null;
        if (!is_string($dtoClass) || !is_subclass_of($dtoClass, ActionPayloadDTO::class)) {
            return null;
        }

        return $dtoClass::fromArray($data);
    }
}
