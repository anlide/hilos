<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\API\Router\Exception\GroupSubscriptionNotFoundException;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\BrokenSignalPayloadDtoException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\GroupNameMatch;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Page\Config\PageAgentIndexRoute;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\AllClientsDestination;
use Hilos\Core\Router\Destination\CommandReplyDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\Destination\RemoteClientDestination;
use Hilos\Core\Router\Destination\RemoteFanoutDestination;
use Hilos\Core\Router\Destination\SessionClientsDestination;
use Hilos\Core\Router\Destination\UnknownAgentDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Environment\Exception\EnvException;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Hilos;
use Hilos\Mail\HilosMailer;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;
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
use Throwable;

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
    /**
     * @var array<string, list<string>> Signal sources each source-constrained signal type is
     *     accepted from. A type absent from this table is not source-constrained at all.
     *
     * An agent signal is the same signal whichever of our processes queued it: its route is
     * picked by signal name from the declared registry, so the source only tells one of our
     * own processes from another, and the master is ours as much as a worker or an agent is.
     * The master is named here because it does queue agent signals of its own - the alert
     * about a node frozen with nothing happening behind it (HIL-482) is raised on the master,
     * with no database and no agent of its own behind it. Today that particular message would
     * pass without this entry, because the raw-send intake stamps every message it takes with
     * one source of its own ({@see HilosMailer::send()}) whoever called it; the row says what
     * is true of the master rather than leaning on where one caller's stamp comes from. Cron
     * and binary frames keep a single source each, because there the source really does pick
     * the transport the signal may arrive over.
     */
    private const array ALLOWED_SIGNAL_SOURCES = [
        SignalTypeConstants::AGENT_SIGNAL => [SignalSource::AGENT, SignalSource::WORKER, SignalSource::DAEMON],
        SignalTypeConstants::CRON => [SignalSource::DAEMON],
        SignalTypeConstants::FRAME_BINARY => [SignalSource::WEBSOCKET],
    ];

    /**
     * @var list<string> Signal types whose route is taken from a declared registry, so that an
     *     empty destination list means the route is missing rather than idle.
     */
    private const array DESTINATION_EXPECTED_SIGNAL_TYPES = [
        SignalTypeConstants::AGENT_SIGNAL,
        SignalTypeConstants::ACTION,
        SignalTypeConstants::COMMAND_REQUEST,
    ];

    /**
     * @var list<string> Signal types delivered to browsers by fan-out rather than by address,
     *     so that on a cluster they have to be carried to every node instead of resolved here.
     *
     * All four are answered from the subscription registry or the connection list of one node
     * ({@see getWebSocketDestinations()}), which is exactly why the cluster cannot resolve them
     * centrally: this node knows only its own. ws_user is absent because it names its target,
     * and a named target is an address the connection index can place. ws_session names something
     * too, but not an address: a session is a set of sockets, and only the node holding them can
     * say which ones - the index maps accept keys, and there is no accept key here to map.
     */
    private const array CLIENT_FANOUT_SIGNAL_TYPES = [
        SignalTypeConstants::WS_ALL,
        SignalTypeConstants::WS_ALL_CONNECTED,
        SignalTypeConstants::WS_GROUP,
        SignalTypeConstants::WS_SESSION,
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
     * @var string Identity of this process as the emitter of the DB syncs it sends.
     *
     * A clear fact has no row id, so the self-broadcast registry cannot key it the way
     * row syncs are keyed. The emitter identity replaces that registration entirely:
     * it travels in the payload and is compared on receive, so suppression holds no
     * state that could be evicted. Random rather than the worker index because indexes
     * are reused after a worker restart, and an echo from the dead worker would then
     * suppress a legitimate clear in its successor.
     *
     * A row sync carries the same stamp, but there it narrows the registration rather
     * than replacing it: the registry says a row is awaited back, the stamp says by
     * whom, and only both together mean "my own echo".
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
     * Returns the emitter identity stamped on the DB syncs this process sends.
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
     * Covers CONNECTION_CLOSE and WEBSOCKET/CRON, and HANDSHAKE only in a project that
     * registers no sessions library: the handshake is the one of the three that resolves a
     * session, so where a library owns sessions it is addressed there instead (HIL-710).
     * The close stays here because the connection row it ends is the project's.
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

        if ($source === SignalSource::WEBSOCKET && $signalTypeValue === SignalTypeConstants::HANDSHAKE) {
            return $this->nonEmptyAgentTypes([$this->handshakeAgentType()]);
        }

        if ($source === SignalSource::WEBSOCKET && in_array($signalTypeValue, [
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CRON,
        ], true)) {
            return $this->nonEmptyAgentTypes([$this->getDefaultWebSocketLifecycleAgentType()]);
        }

        return [];
    }

    /**
     * Names the agent a handshake is delivered to.
     *
     * Asked of the registry rather than of a project hook, because registering the sessions
     * library IS the declaration (HIL-710): a project that has one wants its handshakes
     * resolved there, and one that has none - the cluster demo - keeps the lifecycle owner
     * it always had. A second place to say so could only ever disagree with the first.
     *
     * Addressing the handshake to the library is also what keeps the move free: the session
     * is resolved where it lives, so a page load costs no hop it did not cost before.
     *
     * @return ?string Agent type the handshake is routed to, or null when neither is declared
     */
    private function handshakeAgentType(): ?string
    {
        $agents = $this->hilosClass()::AGENTS;
        if (array_key_exists(HilosAgentType::HILOS_SESSIONS_LIBRARY, $agents)) {
            return HilosAgentType::HILOS_SESSIONS_LIBRARY;
        }

        return $this->getDefaultWebSocketLifecycleAgentType();
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
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
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
     * Stamps the payload with this process's emitter identity, the way
     * {@see self::queueDbSyncClearedSignal()} does: the registration alone says a row
     * is awaited back, not from whom, so the receiving side needs the stamp to tell
     * this process's own echo from another writer's change to the same row.
     *
     * @param string $signalName Signal name (e.g. SignalConstants::DB_SYNC_CREATED)
     * @param DbSyncSignalDataInterface $signalData Signal data with collectionKey and idString
     * @throws InvalidArgumentException When the signal name is empty
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
            signalData: $signalData->withEmitter($this->emitter),
        );
    }

    /**
     * Check if apply should be skipped (self-broadcast) and remove from registry.
     *
     * The verdict is keyed by the PAIR "emitter stamp, then pending registration". The
     * stamp is compared first, and a foreign or missing one returns before the registry
     * is read at all — which is the whole point of the order. A frame from another
     * writer used to consume the registration standing for THIS process's write to the
     * same row, so two changes were lost at once: the foreign one was dropped as an own
     * echo, and this process's own echo later passed through unsuppressed and reached
     * the agents as someone else's fact.
     *
     * An unstamped frame counts as someone else's, the same way an unstamped clear does
     * ({@see self::shouldSkipDbSyncClearApply()}).
     *
     * @param string $collectionKey Collection key for sync
     * @param string $idString Entity ID string
     * @param ?string $emitter Emitter identity from the sync payload, null when unstamped
     * @return bool True if this was our broadcast, skip apply
     */
    public function shouldSkipDbSyncApply(string $collectionKey, string $idString, ?string $emitter): bool
    {
        if ($emitter !== $this->emitter) {
            if ($this->dbSelfBroadcast->has($collectionKey, $idString)) {
                // The only place a genuine two-writer race on one row is visible from
                // the outside: this process is still waiting for the echo of its own
                // write when someone else's change to the same row arrives.
                Logger::warning(
                    "DB sync: a foreign write to row {$idString} of collection {$collectionKey} arrived "
                    . 'while this process was still awaiting the echo of its own write to that row',
                );
            }

            return false;
        }

        return $this->dbSelfBroadcast->consume($collectionKey, $idString);
    }

    /**
     * Queue a DB sync cleared signal (collection-scoped truncate).
     * Skips if broadcast disabled. Stamps the payload with this process's emitter identity.
     *
     * @param DbSyncClearedSignalData $signalData Cleared signal data with collectionKey
     * @throws InvalidArgumentException When the cleared signal cannot be named
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
     * @throws InvalidArgumentException When the signal name is empty
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
     * Resolves which group a connection holds under a name the client wrote.
     *
     * The client names a group of "my" entity without the entity, so an update or a leave
     * arrives naming the head of the membership the server recorded. Both sides of the
     * subscription - the master's registry and each worker's mirror - answer this the same
     * way, which is what keeps the two from drifting apart on the same frame.
     *
     * @param string $acceptKey Client accept key
     * @param string $group Group name as the client wrote it
     * @return ?string Full group name this connection holds, or null when it holds none
     */
    public function groupSubscriptionName(string $acceptKey, string $group): ?string
    {
        return $this->subscriptions->groupSubscriptionName($acceptKey, $group);
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
     * Reads the per-instance route a page declared, from project topology.
     *
     * The single reader of the per-instance registry: the router asks it to decide whether a
     * signal is addressed off the subscription record, and the master asks it to resolve the
     * address in the first place ({@see DaemonManager}).
     *
     * @param string $page Page name
     * @return ?PageAgentIndexRoute Declared route, or null when the page is not per-instance
     */
    public function pageAgentIndexRoute(string $page): ?PageAgentIndexRoute
    {
        return $this->hilosClass()::getPageAgentIndexRoutes()[$page] ?? null;
    }

    /**
     * Returns a connection's page subscription, or null when it holds none.
     *
     * @param string $acceptKey Accept key identifier
     * @return ?PageSubscription Stored subscription, or null when the connection holds none
     */
    public function pageSubscription(string $acceptKey): ?PageSubscription
    {
        return $this->subscriptions->pageSubscription($acceptKey);
    }

    /**
     * Records which agent serves a connection's page subscription.
     *
     * @param string $acceptKey Accept key identifier
     * @param string $page Page identifier that must match the stored page
     * @param string $agentType Agent type serving the subscription
     * @param ?string $agentIndex Instance index, or null to serve the subscription unindexed
     */
    public function bindPageAgent(string $acceptKey, string $page, string $agentType, ?string $agentIndex): void
    {
        $this->subscriptions->bindPageAgent($acceptKey, $page, $agentType, $agentIndex);
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
     * @throws EnvException When the placement post-pass reads cluster configuration and it is invalid
     */
    public function getDestinations(SignalDTO $signal): array
    {
        $destinations = [
            ...$this->getWebSocketDestinations($signal),
            ...$this->getClientFanoutDestinations($signal),
            ...$this->getPageSubscriptionDestinations($signal),
            ...$this->getGroupSubscriptionDestinations($signal),
            ...$this->getActionDestinations($signal),
            ...$this->getCommandDestinations($signal),
            ...$this->getAgentDestinations($signal),
            ...$this->getPageOwnedSignalDestinations($signal),
            ...$this->additionalDestinations($signal),
        ];

        return $this->applyClientLocation($this->applyPlacement($this->dedupeDestinations($destinations)));
    }

    /**
     * Tells whether this signal was supposed to reach somebody.
     *
     * True only for the types whose route comes from a declared registry — an agent signal,
     * a page action, a command request. For those an empty destination list has a single
     * meaning: no route was ever declared under that name, and the signal is being dropped.
     * Every other type is routed by live state — who is subscribed, who is connected — where
     * an empty list is the ordinary case and says nothing about the topology.
     *
     * Public because the daemon asks it at the one point where the empty list is known —
     * after every contributor has had its say — and because that keeps the answer testable
     * on its own, without driving a daemon to reach it.
     *
     * @param SignalDTO $signal Signal DTO
     * @return bool True when an empty destination list means a missing route
     */
    public function expectsDestination(SignalDTO $signal): bool
    {
        return in_array($signal->signalType->getType(), self::DESTINATION_EXPECTED_SIGNAL_TYPES, true);
    }

    /**
     * Resolves the browsers of THIS node a signal is meant for, and nothing beyond them.
     *
     * The entry point for the receiving end of a cross-node fan-out: the frame arrives already
     * decided, and all that is left is asking this node's own subscription registry who it goes
     * to. Deliberately not {@see getDestinations()} — that one contributes agents, actions and
     * command replies the sending node has already dealt with, and it would append the fan-out
     * marker again, turning one broadcast into a mesh-wide storm. Skipping it is the one-hop
     * rule expressed as a call, not as a hop counter.
     *
     * The cross-node post-passes are skipped for the same reason: every accept key this
     * registry holds is a socket of this node, so there is nothing here to place elsewhere.
     *
     * @param SignalDTO $signal Signal to resolve against this node's subscriptions
     * @return list<Destination> WebSocket client destinations, or a single all-clients broadcast marker
     */
    public function localClientDestinations(SignalDTO $signal): array
    {
        return $this->getWebSocketDestinations($signal);
    }

    /**
     * Answers where one named agent runs, as the destination that reaches it there.
     *
     * The placement post-pass made available to a caller that has an agent and no list. The
     * ordinary route resolves destinations and places them in one pass, but two deliveries in
     * the master do not come from a route at all — the connection-close fan-out and the
     * unsubscribe of a replaced subscription build their destination from a subscription record
     * (HIL-745). Before this, both delivered it locally, which on a follower reaches workers
     * running no such agent and on a leader starts a second instance of the entity's owner.
     *
     * Same three answers as the walk, decided by the same lookup: the agent stays an
     * {@see AgentDestination} when it runs here, becomes a {@see RemoteAgentDestination} when
     * another node hosts it, and a {@see UnknownAgentDestination} when no node is known to. Off
     * a cluster the lookup does not exist and the destination is returned as it came, so a
     * single node behaves exactly as it did.
     *
     * @param AgentDestination $destination Agent to locate
     * @return AgentAddressedDestination Destination reaching that agent where it actually runs
     * @throws EnvException When the placement lookup reads cluster configuration and it is invalid
     */
    public function placeAgentDestination(AgentDestination $destination): AgentAddressedDestination
    {
        // The post-pass rewrites in place, so a list of one comes back as a list of one, and
        // every rewrite it can make to an agent destination is itself an addressed agent - as is
        // the untouched input when there is no cluster. Nothing here narrows that back down: the
        // declared return type is the guard, and it is one this branch cannot trip.
        return $this->applyPlacement([$destination])[0];
    }

    /**
     * Rewrites agent destinations by where the placement lookup says the agent runs.
     *
     * Cross-node routing preserves the declarative route-by-sender model: destinations are
     * resolved exactly as before, then this post-pass asks the placement lookup where each
     * agent lives, and the three answers become three destinations. An agent on another node
     * becomes a {@see RemoteAgentDestination} the daemon forwards over the peer channel; an
     * agent of unknown whereabouts becomes an {@see UnknownAgentDestination} the daemon drops
     * with a log; a local agent stays an {@see AgentDestination} and is delivered here.
     *
     * The unknown case is the one worth naming (HIL-670): it used to be the local case, because
     * the lookup answered null for both, so a node with no placement picture delivered the
     * signal into its own workers — which run no such agent, and say nothing about it.
     * Off-cluster, or on a node with no registered lookup, there is no lookup and the list is
     * returned untouched, so single-node behaviour is unchanged.
     *
     * Only {@see AgentDestination} is eligible: a browser is placed by its own post-pass
     * ({@see applyClientLocation()}) and a command reply is bound to the connection this node is
     * holding open.
     *
     * @param list<Destination> $destinations Resolved destinations before placement
     * @return list<Destination> Destinations with cross-node and unaddressable agents rewritten
     * @throws EnvException When the placement lookup reads cluster configuration and it is invalid
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

            $location = $placement->locate($destination->agentType, $destination->agentIndex);
            if ($location->kind === AgentLocationKind::Here) {
                continue;
            }

            // A location naming a node carries its id and no other location does, so reading the
            // id is how the remaining two cases are told apart - not a second opinion about what
            // the lookup already answered.
            $destinations[$index] = $location->nodeId !== null
                ? new RemoteAgentDestination($location->nodeId, $destination->agentType, $destination->agentIndex)
                : new UnknownAgentDestination($destination->agentType, $destination->agentIndex);
        }

        return $destinations;
    }

    /**
     * Rewrites client destinations that resolve to another node into remote destinations.
     *
     * The browser-side twin of {@see applyPlacement()}, and it runs after it for a reason worth
     * naming: the two post-passes are about different halves of the same signal, and neither
     * can turn the other's destinations into its own — a {@see WebSocketDestination} is never
     * an agent and an {@see AgentDestination} is never a socket. Running them in sequence is
     * therefore order-independent, and this order is only the one the list reads in.
     *
     * A connection held by another node becomes a {@see RemoteClientDestination} the daemon
     * forwards over the peer channel; a connection attached here (or any key the lookup reports
     * null for) stays a {@see WebSocketDestination} and is written to locally. Off-cluster, or
     * on a node with no registered lookup, the list is returned untouched, so single-node
     * behaviour is unchanged.
     *
     * Only {@see WebSocketDestination} is eligible. {@see AllClientsDestination} and
     * {@see SessionClientsDestination} are deliberately left alone: neither is an address but an
     * instruction to fan out, and the node that holds the connections is the only one that can
     * carry it out — their cross-node half is a separate frame, not a rewritten destination.
     *
     * @param list<Destination> $destinations Resolved destinations before the connection lookup
     * @return list<Destination> Destinations with cross-node clients rewritten to remote
     */
    private function applyClientLocation(array $destinations): array
    {
        $location = Hilos::$cluster?->clientLocation();
        if ($location === null) {
            return $destinations;
        }

        foreach ($destinations as $index => $destination) {
            if (!$destination instanceof WebSocketDestination) {
                continue;
            }

            $nodeId = $location->nodeFor($destination->acceptKey);
            if ($nodeId !== null) {
                $destinations[$index] = new RemoteClientDestination($nodeId, $destination->acceptKey);
            }
        }

        return $destinations;
    }

    /**
     * Adds the marker that carries a browser fan-out to the other nodes of the cluster.
     *
     * Added ALONGSIDE the local destinations rather than in place of them, which is what makes
     * it unlike the two post-passes above: they rewrite a target that turned out to live
     * elsewhere, while a fan-out has no single target at all. This node still fans out to its
     * own browsers exactly as before; the marker is the other nodes' half of the same job, and
     * {@see DaemonManager} turns it into one broadcast frame.
     *
     * The frame goes out whether or not an agent, a peer, or anyone else is remote, because
     * the alternative is a flag nobody can compute honestly: a node cannot tell from a signal
     * whether some other node holds a subscriber. It is also what fixes today's silent hole,
     * where a ws_all raised on one node reached only the browsers attached to that node.
     *
     * Contributed only where a connection lookup is registered — that is, on a clustered
     * daemon, which is the one place both client-side cluster seams are installed together.
     * Off-cluster, and in every worker, the list stays empty and delivery is local as before.
     *
     * @param SignalDTO $signal Signal DTO
     * @return list<Destination> The single fan-out marker, or empty when it is not one / not clustered
     */
    private function getClientFanoutDestinations(SignalDTO $signal): array
    {
        if (!in_array($signal->signalType->getType(), self::CLIENT_FANOUT_SIGNAL_TYPES, true)) {
            return [];
        }

        return Hilos::$cluster?->clientLocation() !== null
            ? [new RemoteFanoutDestination()]
            : [];
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
                $destination instanceof SessionClientsDestination =>
                    [SessionClientsDestination::class, $destination->sessionTokenHash],
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

        // An action on a per-instance page goes to the very instance the caller is
        // subscribed to, and to nothing else: with no live subscription there is no
        // instance to name, and acting on a page one is not subscribed to has never
        // meant anything anyway (HIL-627).
        $ownerPage = $this->hilosClass()::getPageActionRoutes()[$actionName] ?? null;
        if (is_string($ownerPage) && $this->pageAgentIndexRoute($ownerPage) !== null) {
            $boundDestination = $this->boundPageAgentDestination($signal, $ownerPage);
            if ($boundDestination === null) {
                Logger::error(
                    "Action {$actionName} on per-instance page {$ownerPage} has no destination"
                    . ' - the connection holds no bound subscription to that page',
                );

                return [];
            }

            return [$boundDestination];
        }

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
            // A re-decision goes exactly where the subscribe it re-judges went: same page,
            // same owning agent, same resolution. It carries the subscribe payload for that
            // reason and not out of convenience ({@see PageAccessReassessment}).
            SignalTypeConstants::PAGE_ACCESS_REASSESS => $data instanceof WebSocketPageSubscribeSignalDTO ? $data->page : null,
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

        // A per-instance page is addressed off its subscription record, which the master
        // resolved once and bound there (HIL-627). Reading it here and computing it there
        // keeps routing a pure function of the registry: resolution, re-resolution, and the
        // check that an update did not move the instance all live where the mutation lives.
        $boundDestination = $this->boundPageAgentDestination($signal, $page);
        if ($boundDestination !== null) {
            return [$boundDestination];
        }

        // A per-instance page has no type-level addressee to fall back to: the agent type it
        // is served by is an indexed one, and naming it without an index would address an
        // instance nobody asked for and nobody can serve. No record means no addressee and a
        // line in the log, exactly as an action without one (HIL-627).
        if ($this->pageAgentIndexRoute($page) !== null) {
            Logger::error(
                "Signal {$signalType} on per-instance page {$page} has no destination"
                . ' - the connection holds no bound subscription to that page',
            );

            return [];
        }

        $agentType = $this->getPageSubscriptionAgentType($page);
        if ($agentType === null) {
            return [];
        }

        return [new AgentDestination($agentType)];
    }

    /**
     * Reads the agent bound to this connection's subscription, for a per-instance page only.
     *
     * Null for a page that declares no per-instance route: it is served by its agent type,
     * through the very code path it always was. Null too when the page declares one but the
     * connection holds no matching bound record - and there the caller has no type to fall
     * back to, so it drops the signal with a line in the log rather than address an instance
     * nobody asked for.
     *
     * @param SignalDTO $signal Signal being routed
     * @param string $page Page the signal names
     * @return ?AgentDestination Bound destination, or null when the page is not per-instance
     */
    private function boundPageAgentDestination(SignalDTO $signal, string $page): ?AgentDestination
    {
        if ($this->pageAgentIndexRoute($page) === null) {
            return null;
        }

        $data = $signal->data;
        if (!$data instanceof WebSocketAcceptKeySignalDTO) {
            return null;
        }

        $acceptKey = $data->getAcceptKey();
        if ($acceptKey === null || $acceptKey === '') {
            return null;
        }

        $subscription = $this->subscriptions->pageSubscription($acceptKey);
        if ($subscription === null || $subscription->page !== $page || $subscription->agentType === null) {
            return null;
        }

        return new AgentDestination($subscription->agentType, $subscription->agentIndex);
    }

    /**
     * Resolves page subscription owner from project topology.
     *
     * Public because the master resolves the same question when it addresses a per-instance
     * subscription ({@see DaemonManager}): the project facade is named in one place, here,
     * and a second reader of the registry would be a second thing to keep in step.
     *
     * @param string $page Page name from the subscription signal
     * @return ?string Agent type or null when no route exists
     */
    public function getPageSubscriptionAgentType(string $page): ?string
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
     * The name arrives as the client wrote it, so it is matched the way the worker matches it
     * ({@see GroupNameMatch::resolve()}): exactly, then by its head. A name with a param has to
     * reach its owner even when the param is wrong for that group - the refusal is a frame the
     * group layer sends, and a frame nobody routes is the silence this leaf exists to remove.
     *
     * @param string $group Group name from the subscription signal
     * @return ?string Agent type or null when no route exists
     */
    private function getGroupSubscriptionAgentType(string $group): ?string
    {
        $match = $this->resolveGroupName($group);
        $agentType = $match === null
            ? null
            : $this->hilosClass()::getGroupRoutes()[$match->groupClass::GROUP] ?? null;
        if (is_string($agentType) && $agentType !== '') {
            return $agentType;
        }

        $fallbackAgentType = $this->getDefaultGroupSubscriptionAgentType();

        return is_string($fallbackAgentType) && $fallbackAgentType !== ''
            ? $fallbackAgentType
            : null;
    }

    /**
     * Resolves a group name off the wire to the registered class that answers it.
     *
     * Public, and the ONLY way to ask this question, for the reason
     * {@see self::getPageSubscriptionAgentType()} is public: the project facade is named in one
     * place - {@see self::hilosClass()}, which every project router overrides - and a second
     * reader of the registry is a second thing to keep in step. Reading it through the base
     * facade instead looks identical and answers nothing, because late static binding resolves
     * `static::GROUPS` to {@see Hilos} itself, whose registry is empty by construction.
     *
     * @param string $group Group name as the client wrote it
     * @return ?GroupNameMatch Registered class and the param the name carried, or null when none answers it
     */
    public function resolveGroupName(string $group): ?GroupNameMatch
    {
        return GroupNameMatch::resolve($group, $this->hilosClass()::getGroupClasses());
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
        if (!$this->acceptsSource($signal)) {
            return null;
        }

        $signalType = $signal->signalType->getType();
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
     * Tells whether this signal type is routed from the source the signal carries.
     *
     * Both agent-signal branches ask this one table — the page-owned one
     * ({@see getPageSignalAgentType()}) and the agent-owned one
     * ({@see getAgentDestinations()}) — so the two cannot drift apart into a rule that
     * accepts a signal down one path and silently drops it down the other.
     *
     * @param SignalDTO $signal Signal DTO
     * @return bool True when the type is source-constrained and this source is one of its own
     */
    private function acceptsSource(SignalDTO $signal): bool
    {
        $allowedSources = self::ALLOWED_SIGNAL_SOURCES[$signal->signalType->getType()] ?? null;

        return $allowedSources !== null
            && in_array($signal->signalSource->getSource(), $allowedSources, true);
    }

    /**
     * Get WebSocket destinations for signal
     *
     * Returns array of WebSocket client destinations based on signal type and subscriptions.
     * For ws_user: returns single client with targetAcceptKey
     * For ws_session: returns a single session fan-out marker carrying targetSessionTokenHash
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
        $targetSessionTokenHash = null;
        $targetGroup = null;
        $excludeAcceptKey = null;

        if ($signalData instanceof WebSocketSignalData) {
            $targetAcceptKey = $signalData->targetAcceptKey;
            $targetSessionTokenHash = $signalData->targetSessionTokenHash;
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

            case SignalTypeConstants::WS_SESSION:
                // Single fan-out marker; the daemon picks its own clients by session token hash
                if ($targetSessionTokenHash !== null) {
                    $destinations[] = new SessionClientsDestination($targetSessionTokenHash);
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

        if (!$this->acceptsSource($signal)) {
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
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
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
