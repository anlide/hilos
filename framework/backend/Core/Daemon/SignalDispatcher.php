<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;
use Hilos\Cluster\AgentSignalMesh;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementRecovery;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\AllClientsDestination;
use Hilos\Core\Router\Destination\CommandReplyDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\HttpReplyDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\Destination\RemoteClientDestination;
use Hilos\Core\Router\Destination\RemoteFanoutDestination;
use Hilos\Core\Router\Destination\RemoteHttpReplyDestination;
use Hilos\Core\Router\Destination\SessionClientsDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\LocalClientSignalBatch;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\Delivery\DeliveryCleanup;
use Hilos\TruthSource\RtReplicaConfirmation;
use Hilos\Utils\Logger;
use Throwable;
use WeakReference;

/** Owns retained signal queues, fair admission and finite prefix accounting for one master. */
class SignalDispatcher
{
    private const int SIGNAL_DISPATCH_STEPS_PER_PORTION = 64;
    private const int AGENT_REPORT_STEPS_PER_PORTION = 64;
    /** Up to four maintenance steps per group; idle reservations belong to delivery. */
    private const int SIGNAL_DISPATCH_SCHEDULING_GROUP_STEPS = 8;
    private const int SIGNAL_RELEASE_SERVICE = 0;
    private const int SIGNAL_ORDINARY_SERVICE = 1;
    private const int SIGNAL_CANCELLATION_SERVICE = 2;
    private const int SIGNAL_EFFECT_SERVICE = 3;
    private const int SIGNAL_SERVICE_COUNT = 4;
    private const int SIGNAL_IDENTITY_ADMISSION = 0;
    private const int SIGNAL_PEER_ADMISSION = 1;
    private const int SIGNAL_INPUT_ADMISSION = 2;
    private const int SIGNAL_ADMISSION_COUNT = 3;

    /**
     * @var int Milliseconds a subscription waits for the identity behind its connection before
     *     it is routed on whatever is known (HIL-627)
     *
     * Deliberately its own number and not the worker's {@see PageSignalRouter} wait under a shared
     * name. The two happen to be equal today and are answerable to different things: the worker
     * waits for an RT row to reach the worker that judges a frame, this one waits for it to reach
     * the master that addresses a subscription. Either may move without the other.
     */
    private const int SUBSCRIPTION_IDENTITY_WAIT_TIMEOUT_MS = 500;

    /** Retained work belongs only to this master process and is never replicated. */
    private ?SignalDispatchState $signalDispatchState = null;

    /** @var ?ReadyWork Native wake ticket; dispatch remains in its guarded phase after source announcements */
    private ?ReadyWork $signalDispatchWake = null;

    /** Exact process-local binding token; a departed owner cannot detach its replacement. */
    private ?Closure $signalDispatchNotifier = null;

    /** @var ?SignalRouter Exact router incarnation whose local enqueue cue this master owns */
    private ?SignalRouter $signalDispatchRouter = null;

    /** @var ?WorkerServer Exact server whose empty stop barrier wakes that same dispatch phase */
    private ?WorkerServer $signalDispatchWorkerServer = null;

    /** @var ?WeakReference<AgentManagerDaemon> Exact report owner bound to this master's source FIFO */
    private ?WeakReference $signalDispatchAgentManager = null;

    /** @var ?WeakReference<PeerServer> Exact source-interest transport on the same guarded source FIFO */
    private ?WeakReference $signalDispatchPeerServer = null;

    /** @var ?Closure(Closure(SignalDispatch): Generator<int, void>): void Weak binding; no escaped peer retains its old manager */
    private ?Closure $sourceInterestDeliveryBinding = null;

    private ?ClusterPlacement $signalHoldPlacement = null;

    private ?WorkerServer $signalHoldWorkerServer = null;

    /** @var ?Closure(?string): void Exact local placement binding, independent of readiness */
    private ?Closure $placementHoldWake = null;

    /** @var ?Closure(?string): void Exact local start/resume binding, independent of capacity and stop dispatch */
    private ?Closure $workerHoldWake = null;

    /** @var array<string, EventLoopTimer> Only addresses with unresolved placement obligations */
    private array $placementSignalTimers = [];

    private int $signalHoldGeneration = 0;

    /** @var array<int, ParkedSignal> Signals held until their identity arrives; stable keys support finite live probes */
    private array $parkedSignals = [];

    private int $parkedSignalSequence = 0;

    /** Scalar connection/page index across the original parked and ready identity lifetimes. */
    private ?IdentitySignalIndex $identitySignals = null;

    private ?EventLoopTimer $identitySignalTimer = null;

    private bool $identityDeadlinePending = false;

    /** @var ?WeakReference<RtContext> Runtime incarnation used by the last guarded identity discovery */
    private ?WeakReference $identityRuntime = null;

    /** @var ?WeakReference<BrowserContext> Project identity resolver incarnation */
    private ?WeakReference $identityBrowser = null;

    /** Retired socket arrays remain private to this master's guarded effect queue. */
    private ?DeliveryCleanup $deliveryCleanup = null;

    /** @var array<string, array<string, PendingPageDelivery>> Connection and attempt to selected serving address */
    private array $pendingPageDeliveries = [];

    /** Scalar address and lifetime index; the original ledger above keeps sole payload ownership. */
    private ?PendingPageIndex $pendingPageIndex = null;

    /** Sole local payload owner for blocked, released and active one-agent obligations. */
    private ?AgentSignalHolds $agentSignalHolds = null;

    /** Exact native base used only by this attachment's timers. */
    private ?EventLoop $eventLoop = null;

    /** @var ?Closure(Closure(SignalDispatch): Generator<int, void>): void Exact reader-interest observer */
    private ?Closure $readerInterestDeliveryBinding = null;

    /** @param SignalDispatchHost $host General master operations, unused until the first entry */
    public function __construct(private readonly SignalDispatchHost $host)
    {
    }

    /** @return ?SignalDispatchState Current progress without creating or rebinding it */
    public function retainedState(): ?SignalDispatchState
    {
        return $this->signalDispatchState;
    }

    /** @throws Throwable First disposal failure, after independent retained lifetimes have cleared */
    public function discardState(): void
    {
        try {
            $this->signalDispatchState?->discard();
        } finally {
            $this->signalDispatchState = null;
        }
    }

    /** @return bool Whether the captured final browser boundary has finished accounting */
    public function isBrowserPrefixComplete(): bool
    {
        return $this->signalDispatchState?->browsers?->complete ?? false;
    }

    /**
     * @param ?WorkerServer $workers Original worker transport
     * @param ?WebSocketServer $browsers Original browser transport
     * @param ?CommandServer $commands Original command transport
     * @param ?HttpServer $http Original HTTP transport
     * @param ?PeerServer $peers Original peer transport
     * @param bool $shuttingDown Whether the master has begun shutdown
     * @throws Throwable When the guarded portion or its accounting fails
     */
    public function dispatch(?WorkerServer $workers, ?WebSocketServer $browsers, ?CommandServer $commands,
        ?HttpServer $http, ?PeerServer $peers, bool $shuttingDown): void
    {
        $this->captureSignalDispatchPrefixes($workers, $shuttingDown);
        try {
            $this->drainSignals($workers, $browsers, $commands, $http, $peers);
        } finally {
            $this->host->afterSignalDispatch();
        }
        $state = $this->signalDispatchState();
        if ($state->stop?->isReadyForAccounting()) {
            $state->stop->completeAfterAccounting();
            $state->stop->workerServer->completeStopDispatch($state->stop->workerGeneration, []);
        }
        if ($state->browsers?->isReadyForAccounting()) {
            $state->browsers->completeAfterAccounting();
        }
    }

    /**
     * @param SignalDispatch $effect Newly enqueued root or cleanup eligible for inline progress
     * @throws Throwable When the original bounded portion fails
     */
    public function advanceInitialSignalEffect(SignalDispatch $effect): void
    {
        $state = $this->signalDispatchState();
        if (key($state->effects) === $effect->effectId) {
            for ($step = 0; $step < self::AGENT_REPORT_STEPS_PER_PORTION && isset($state->effects[$effect->effectId]); $step++) {
                $this->advanceSignalEffect($effect->effectId);
            }
        }
    }

    /**
     * Advance original FIFO and physical continuations; completed payload locals end before accounting.
     *
     * @param ?WorkerServer $workerServer Original worker transport
     * @param ?WebSocketServer $webSocketServer Original browser transport
     * @param ?CommandServer $commandServer Original command transport
     * @param ?HttpServer $httpServer Original HTTP transport
     * @param ?PeerServer $peerServer Original peer transport
     * @throws InvalidArgumentException When a queued signal cannot be named
     * @throws EnvException When destination configuration is invalid
     * @throws ClusterConfigurationException When a node-addressed destination cannot resolve its node
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function drainSignals(
        ?WorkerServer $workerServer, ?WebSocketServer $webSocketServer, ?CommandServer $commandServer,
        ?HttpServer $httpServer, ?PeerServer $peerServer,
    ): void
    {
        if ($workerServer === null) {
            $state = $this->signalDispatchState();
            for ($step = 0; $step < self::SIGNAL_DISPATCH_STEPS_PER_PORTION; $step++) {
                if ($step % self::SIGNAL_DISPATCH_SCHEDULING_GROUP_STEPS === 0 && ($state->stop?->hasRunnableValidation() ?? false)) {
                    $state->stop?->advanceValidation($this->agentSignalHolds(), key($state->identities),
                        key($state->peers), key($state->effects));
                    continue;
                }
                if ($step % self::SIGNAL_DISPATCH_SCHEDULING_GROUP_STEPS === 1 && ($state->browsers?->hasRunnableValidation() ?? false)) {
                    $state->browsers?->advanceValidation($this->agentSignalHolds(), key($state->identities),
                    key($state->peers), key($state->effects));
                    continue;
                }
                switch ($this->selectSignalService(false)) {
                    case self::SIGNAL_ORDINARY_SERVICE:
                        if ($state->active !== null) {
                            $this->advanceSignalDelivery($state->active);
                        } else {
                            $peerId = key($state->peers);
                            $state->active = $state->peers[$peerId];
                            unset($state->peers[$peerId]);
                            $this->trackSignalDispatch($state->active);
                        }
                        break;
                    case self::SIGNAL_CANCELLATION_SERVICE:
                        $this->settleCommandCancellation($this->readyCommandCancellationId());
                        break;
                    case self::SIGNAL_EFFECT_SERVICE:
                        $this->advanceSignalEffect(key($state->effects));
                        break;
                }
            }
            return;
        }
        $state = $this->signalDispatchState();
        for ($step = 0; $step < self::SIGNAL_DISPATCH_STEPS_PER_PORTION; $step++) {
            // A fixed share keeps finite validation progressing under continuous later intake.
            if ($step % self::SIGNAL_DISPATCH_SCHEDULING_GROUP_STEPS === 0 && ($state->stop?->hasRunnableValidation() ?? false)) {
                $state->stop?->advanceValidation($this->agentSignalHolds(), key($state->identities), key($state->peers), key($state->effects));
                continue;
            }
            if ($step % self::SIGNAL_DISPATCH_SCHEDULING_GROUP_STEPS === 1 && ($state->browsers?->hasRunnableValidation() ?? false)) {
                $state->browsers?->advanceValidation($this->agentSignalHolds(), key($state->identities),
                    key($state->peers), key($state->effects));
                continue;
            }
            if ($step % self::SIGNAL_DISPATCH_SCHEDULING_GROUP_STEPS === 2
                && ($state->agentProbe !== null || $state->agentDiscoveries !== [])) {
                if ($state->agentProbe === null) {
                    $key = key($state->agentDiscoveries);
                    $state->agentProbe = $state->agentDiscoveries[$key];
                    unset($state->agentDiscoveries[$key]);
                }
                $this->advanceSignalDiscovery($state->agentProbe);
                continue;
            }
            if ($step % self::SIGNAL_DISPATCH_SCHEDULING_GROUP_STEPS === 3
                && ($state->identityProbe !== null || $state->identityDiscoveries !== [])) {
                if ($state->identityProbe === null) {
                    $key = key($state->identityDiscoveries);
                    $state->identityProbe = $state->identityDiscoveries[$key];
                    unset($state->identityDiscoveries[$key]);
                }
                $this->advanceSignalDiscovery($state->identityProbe);
                continue;
            }
            switch ($this->selectSignalService(true)) {
                case self::SIGNAL_RELEASE_SERVICE:
                    $this->advanceReleasedSignal($workerServer, $peerServer);
                    break;
                case self::SIGNAL_ORDINARY_SERVICE:
                    $this->advanceOrdinarySignal($workerServer, $webSocketServer, $commandServer, $httpServer, $peerServer);
                    break;
                case self::SIGNAL_CANCELLATION_SERVICE:
                    $this->settleCommandCancellation($this->readyCommandCancellationId());
                    break;
                case self::SIGNAL_EFFECT_SERVICE:
                    $this->advanceSignalEffect(key($state->effects));
                    break;
            }
        }
    }

    /**
     * Ready categories rotate after each actual step, including failures. Empty categories
     * donate their share; none can grow the wait ahead of another category's next step.
     *
     * @param bool $withWorkers Whether ordinary intake and one-agent releases can be served
     * @return ?int Next nonempty service, absent when only parked work remains
     */
    private function selectSignalService(bool $withWorkers): ?int
    {
        $state = $this->signalDispatchState();
        for ($candidate = 0; $candidate < self::SIGNAL_SERVICE_COUNT; $candidate++) {
            $service = $state->nextService;
            $state->nextService = ($service + 1) % self::SIGNAL_SERVICE_COUNT;
            $ready = match ($service) {
                self::SIGNAL_RELEASE_SERVICE => $withWorkers && ($state->released !== null || $state->suspendedReleases !== []
                    || $this->agentSignalHolds()->hasReleased()),
                self::SIGNAL_ORDINARY_SERVICE => $state->active !== null || $state->peers !== []
                    || ($withWorkers && ($state->identities !== [] || $state->retired !== [] || $state->intake->hasQueuedSignals())),
                self::SIGNAL_CANCELLATION_SERVICE => $this->readyCommandCancellationId() !== null,
                self::SIGNAL_EFFECT_SERVICE => $state->effects !== [],
            };
            if ($ready) {
                return $service;
            }
        }
        return null;
    }

    /**
     * @param WorkerServer $workerServer Original worker transport
     * @param ?PeerServer $peerServer Original peer transport
     * @throws Throwable When a selected hold, predecessor or delivery fails
     */
    private function advanceReleasedSignal(WorkerServer $workerServer, ?PeerServer $peerServer): void
    {
        $state = $this->signalDispatchState();
        if ($state->released?->preemptHoldId !== null) {
            $holdId = $state->released->preemptHoldId;
            $state->released->preemptHoldId = null;
            $hold = $this->agentSignalHolds()->get($holdId);
            if ($hold !== null && $this->agentSignalHolds()->isCanceled($hold)) {
                $this->agentSignalHolds()->settle($holdId);
            } elseif ($hold?->released) {
                $state->suspendedReleases[] = $state->released->heldId;
                $state->released = $this->createReleasedAgentDispatch($hold, $workerServer, $peerServer);
                $this->agentSignalHolds()->begin($holdId, $state->released);
            }
            unset($hold);
            return;
        }
        if ($state->released === null && $state->suspendedReleases !== []) {
            $state->released = $this->agentSignalHolds()->get(array_pop($state->suspendedReleases))?->dispatch;
            return;
        }
        if ($state->released === null && ($holdId = $this->agentSignalHolds()->firstReleasedId()) !== null) {
            $hold = $this->agentSignalHolds()->get($holdId);
            if ($this->agentSignalHolds()->isCanceled($hold)) {
                $this->agentSignalHolds()->settle($holdId);
                unset($hold);
                return;
            }
            $state->released = $this->createReleasedAgentDispatch($hold, $workerServer, $peerServer);
            $this->agentSignalHolds()->begin($holdId, $state->released);
            unset($hold);
            return;
        }
        if ($state->released !== null) {
            $this->advanceSignalDelivery($state->released);
        }
    }

    /**
     * Active ordinary work retains its whole cursor. A discovered same-agent predecessor
     * settles through the existing release slot before this cursor may resume.
     *
     * @param WorkerServer $workerServer Original worker transport
     * @param ?WebSocketServer $webSocketServer Original browser transport
     * @param ?CommandServer $commandServer Original command transport
     * @param ?HttpServer $httpServer Original HTTP transport
     * @param ?PeerServer $peerServer Original peer transport
     * @throws Throwable When an original admission, predecessor or delivery fails
     */
    private function advanceOrdinarySignal(WorkerServer $workerServer, ?WebSocketServer $webSocketServer,
        ?CommandServer $commandServer, ?HttpServer $httpServer, ?PeerServer $peerServer): void
    {
        $state = $this->signalDispatchState();
        if ($state->active !== null) {
            $predecessor = $state->active->preemptHoldId === null ? null : $this->agentSignalHolds()->get($state->active->preemptHoldId);
            if ($predecessor !== null && !$predecessor->isParked()) {
                unset($predecessor);
                $this->advanceReleasedSignal($workerServer, $peerServer);
                return;
            }
            unset($predecessor);
            $state->active->preemptHoldId = null;
            $this->advanceSignalDelivery($state->active);
            return;
        }
        $admission = $this->selectSignalAdmission();
        if ($admission === self::SIGNAL_IDENTITY_ADMISSION) {
            $identityId = key($state->identities);
            $identity = $this->identitySignals()->releasedEntry($identityId);
            if ($identity === null || $this->identitySignals()->isCanceled($identity)) {
                if ($identity !== null) {
                    $this->identitySignals()->settle($identity->id);
                } else {
                    unset($state->identities[$identityId]);
                    $state->stop?->noteIdentitySettled($identityId);
                    $state->browsers?->noteIdentitySettled($identityId);
                }
                unset($identity);
                return;
            }
            $signal = $state->identities[$identityId];
            $this->identitySignals()->settle($identity->id);
            unset($identity);
            $state->active = $this->createSignalDispatch($signal, true, $workerServer, $webSocketServer,
                $commandServer, $httpServer, $peerServer);
            $state->active->identityReleaseId = $identityId;
        } elseif ($admission === self::SIGNAL_PEER_ADMISSION) {
            $peerId = key($state->peers);
            $state->active = $state->peers[$peerId];
            unset($state->peers[$peerId]);
        } elseif (($intakeId = key($state->retired)) !== null) {
            $intake = $state->retired[$intakeId];
            if (!$intake->hasQueuedSignals()) {
                unset($state->retired[$intakeId]);
                return;
            }
            $state->active = $this->admitSignalInput($intake, $workerServer, $webSocketServer,
                $commandServer, $httpServer, $peerServer);
        } elseif ($state->intake->hasQueuedSignals()) {
            $state->active = $this->admitSignalInput($state->intake, $workerServer, $webSocketServer,
                $commandServer, $httpServer, $peerServer);
        }
        $this->trackSignalDispatch($state->active);
        unset($signal, $intake);
    }

    /**
     * Admission rotates between ready identity, peer and original input FIFOs. An active
     * lifetime finishes before another is admitted; each FIFO keeps its original head.
     *
     * @return int Selected ready queue; callers establish that ordinary work is available
     * @throws LogicException When no ordinary queue is ready
     */
    private function selectSignalAdmission(): int
    {
        $state = $this->signalDispatchState();
        for ($candidate = 0; $candidate < self::SIGNAL_ADMISSION_COUNT; $candidate++) {
            $admission = $state->nextAdmission;
            $state->nextAdmission = ($admission + 1) % self::SIGNAL_ADMISSION_COUNT;
            $ready = match ($admission) {
                self::SIGNAL_IDENTITY_ADMISSION => $state->identities !== [],
                self::SIGNAL_PEER_ADMISSION => $state->peers !== [],
                self::SIGNAL_INPUT_ADMISSION => $state->retired !== [] || $state->intake->hasQueuedSignals(),
            };
            if ($ready) {
                return $admission;
            }
        }
        throw new LogicException('No ordinary signal admission is ready');
    }

    /** @return SignalDispatchState Exact original intake and retained guarded progress */
    public function signalDispatchState(): SignalDispatchState
    {
        Hilos::$sr->setSubscriptionCleanup($this->deliveryCleanup());
        $state = $this->signalDispatchState ??= new SignalDispatchState(Hilos::$sr);
        if ($state->intake->router !== Hilos::$sr) {
            $state->intake->retire();
            $state->retiredRemaining += $state->intake->getWatermark() - $state->intake->consumed;
            $state->retired[$state->intake->id] = $state->intake;
            $state->intake = new SignalDispatchIntake(++$state->intakeSequence, Hilos::$sr);
        }
        $this->bindSignalDispatchRouter();
        return $state;
    }

    /**
     * @param SignalDispatchIntake $intake Original consuming router API and its finite position
     * @param WorkerServer $workers Worker transport
     * @param ?WebSocketServer $browsers Browser transport
     * @param ?CommandServer $commands Command transport
     * @param ?HttpServer $http HTTP transport
     * @param ?PeerServer $peers Peer transport
     * @return SignalDispatch Original admitted lifetime
     */
    private function admitSignalInput(SignalDispatchIntake $intake, WorkerServer $workers, ?WebSocketServer $browsers,
        ?CommandServer $commands, ?HttpServer $http, ?PeerServer $peers): SignalDispatch
    {
        $state = $this->signalDispatchState();
        $wasRetired = $intake !== $state->intake;
        $signal = $intake->consumeSignal();
        $state->admissionSequence++;
        if ($wasRetired) {
            $state->retiredRemaining--;
        }
        $dispatch = $signal instanceof LocalClientSignalBatch
            ? new SignalDispatch(null, fn(SignalDispatch $dispatch): Generator =>
                $this->localClientSignalDelivery($dispatch, $signal), cleanup: $this->signalDeliveryCleanup())
            : $this->createSignalDispatch($signal, false, $workers, $browsers, $commands, $http, $peers);
        $dispatch->intakeId = $intake->id;
        $dispatch->inputPosition = $intake->consumed;
        $payload = $signal instanceof SignalDTO ? $signal->data : null;
        if (($payload instanceof CommandRequestDTO || $payload instanceof HttpRequestDTO)
            && ($state->abandonedCommands[$payload->correlationId]->inputTail ?? 0) >= $state->admissionSequence) {
            $dispatch->cancel();
        }
        return $dispatch;
    }

    /**
     * @param SignalDispatch $dispatch Newly admitted ordinary, identity or peer lifetime
     */
    private function trackSignalDispatch(SignalDispatch $dispatch): void
    {
        $state = $this->signalDispatchState();
        if ($state->stop?->track($dispatch)) {
            $dispatch->stopPrefixId = $state->stop->id;
        }
        if ($state->browsers?->track($dispatch)) {
            $dispatch->browserPrefixId = $state->browsers->id;
        }
    }

    /**
     * Later independent effects cannot extend a selected delivery's prerequisites. Descendants
     * retain their causal family; reaching one still drains every older head in the same FIFO.
     *
     * @param ?SignalDispatch $dispatch Selected original or one-agent lifetime
     * @throws Throwable When a prerequisite or delivery fails under the existing manager guard
     */
    private function advanceSignalDelivery(?SignalDispatch &$dispatch): void
    {
        $state = $this->signalDispatchState();
        $dispatch->effectCohortWatermark ??= $state->effectCohortSequence;
        $first = $state->effectCohorts->successor(null);
        if (($first !== null && $first <= $dispatch->effectCohortWatermark)
            || ($dispatch->effectCohortId !== null && isset($state->effectCohortCounts[$dispatch->effectCohortId]))) {
            $effectId = key($state->effects);
            if ($effectId !== null) {
                $this->advanceSignalEffect($effectId);
                return;
            }
        }
        $this->advanceOwnedSignal($dispatch);
    }

    /**
     * @param ?SignalDispatch $dispatch Actual retained owner slot, cleared before receipt accounting
     * @throws Throwable When an ordinary delivery fails under the existing manager guard
     */
    private function advanceOwnedSignal(?SignalDispatch &$dispatch): void
    {
        try {
            $this->runSignalPortion($dispatch, 1);
        } catch (Throwable $failure) {
            if ($dispatch->peerApplyContext !== null) {
                Logger::warning("Failed to apply peer {$dispatch->peerApplyContext}: {$failure->getMessage()}");
            } elseif ($dispatch->peerOrigin !== null) {
                Logger::warning("Failed to fan out peer client signal from node '{$dispatch->peerOrigin}': {$failure->getMessage()}");
            } else {
                throw $failure;
            }
        } finally {
            if (!$dispatch->hasWork()) {
                $state = $this->signalDispatchState();
                $state->stop?->settle($dispatch);
                $state->browsers?->settle($dispatch);
                if ($dispatch->heldId !== null && $this->agentSignalHolds()->get($dispatch->heldId)?->dispatch === $dispatch) {
                    $this->agentSignalHolds()->settle($dispatch->heldId);
                }
                $dispatch = null;
            }
        }
    }

    /**
     * @param ?Generator<int, void> $probe Finite fact or deadline discovery, runnable until its cohort ends
     */
    private function advanceSignalDiscovery(?Generator &$probe): void
    {
        if ($probe->valid()) {
            $probe->next();
        }
        if (!$probe->valid()) {
            $probe = null;
        }
    }

    /**
     * Capture each exact owner/generation and the independent final-browser boundary once.
     *
     * @param ?WorkerServer $workers Original worker transport
     * @param bool $shuttingDown Whether the master has begun shutdown
     * @throws LogicException When a new prefix cannot capture its original intake
     */
    public function captureSignalDispatchPrefixes(?WorkerServer $workers, bool $shuttingDown): void
    {
        $state = $this->signalDispatchState();
        $generation = $workers?->pendingStopDispatch();
        if ($generation !== null && ($state->stop?->workerServer !== $workers || $state->stop?->workerGeneration !== $generation)) {
            $state->stop = $this->newSignalDispatchPrefix($workers, $generation);
        }
        if ($state->browsers === null && $shuttingDown && ($workers === null || $workers->isReadyToShutdown())) {
            $state->browsers = $this->newSignalDispatchPrefix();
        }
    }

    /**
     * @param ?WorkerServer $workers Exact worker stop owner, absent for the independent browser boundary
     * @param ?int $generation Its captured stop generation
     * @return SignalDispatchPrefix Finite metadata debt, including already active physical work
     * @throws LogicException When a prefix cannot capture its original intake
     */
    public function newSignalDispatchPrefix(?WorkerServer $workers = null, ?int $generation = null): SignalDispatchPrefix
    {
        $state = $this->signalDispatchState();
        $prefix = new SignalDispatchPrefix(++$state->prefixSequence, $state->intake, $state->retired,
            $this->agentSignalHolds()->getWatermark(), $state->identitySequence, $state->peerSequence, $workers, $generation,
            $state->effectSequence);
        if ($state->active !== null) {
            $prefix->captureActive($state->active);
            if ($workers !== null) {
                $state->active->stopPrefixId = $prefix->id;
            } else {
                $state->active->browserPrefixId = $prefix->id;
            }
        }
        return $prefix;
    }

    /**
     * @param ?WorkerServer $workers Current worker transport
     * @return bool Actual ready continuations, excluding parked occupancy and retirement
     */
    public function hasRunnableSignalDispatch(?WorkerServer $workers): bool
    {
        $state = $this->signalDispatchState();
        $generation = $workers?->pendingStopDispatch();
        return $state->active !== null || $state->peers !== [] || $state->effects !== []
            || $this->readyCommandCancellationId() !== null
            || ($workers !== null && ($state->identityProbe !== null || $state->identityDiscoveries !== []))
            || ($workers !== null && ($state->agentProbe !== null || $state->agentDiscoveries !== []))
            || ($workers !== null && ($state->released !== null || $state->suspendedReleases !== [] || $this->agentSignalHolds()->hasReleased()
                || $state->identities !== [] || $state->retired !== [] || $state->intake->hasQueuedSignals()))
            || ($state->stop?->hasRunnableValidation() ?? false)
            || ($state->browsers?->hasRunnableValidation() ?? false)
            || ($generation !== null && ($state->stop?->workerServer !== $workers || $state->stop?->workerGeneration !== $generation));
    }

    /** @return ?int Oldest finite caller cancellation ready to forget, excluding unconsumed input */
    private function readyCommandCancellationId(): ?int
    {
        $state = $this->signalDispatchState;
        $id = $state === null ? null : key($state->commandCancellations);
        return $id !== null && $state->commandCancellations[$id]->holdsComplete
            && $state->admissionSequence >= $state->commandCancellations[$id]->inputTail ? $id : null;
    }

    /** @param int $id Exact completed cancellation generation */
    private function settleCommandCancellation(int $id): void
    {
        $state = $this->signalDispatchState();
        $cancellation = $state->commandCancellations[$id];
        unset($state->commandCancellations[$id]);
        if (($state->abandonedCommands[$cancellation->correlationId] ?? null) === $cancellation) {
            unset($state->abandonedCommands[$cancellation->correlationId]);
        }
    }

    /**
     * Keeps a report's original finite cohort before later destination delivery.
     *
     * @param Closure(SignalDispatch): Generator<int, void> $work Report bookkeeping and its ordered final effects
     * @param ?SignalDispatch $origin Explicit causal owner; null starts an independent root
     * @param ?string $peerApplyContext Original peer apply warning context, inherited only by its cleanup effects
     * @param ?RtReplicaConfirmation $rtConfirmation Original RT receipt interval, absent for other lifecycle work
     * @return SignalDispatch Newly queued effect, eligible for a separate bounded initial portion
     */
    public function queueSignalEffect(
        Closure $work,
        ?SignalDispatch $origin,
        ?string $peerApplyContext = null,
        ?RtReplicaConfirmation $rtConfirmation = null,
    ): SignalDispatch {
        $state = $this->signalDispatchState();
        $effect = new SignalDispatch(null, $work, cleanup: $this->signalDeliveryCleanup());
        $effect->effectId = ++$state->effectSequence;
        $effect->peerApplyContext = $peerApplyContext ?? $origin?->peerApplyContext;
        $effect->rtConfirmation = $rtConfirmation;
        $hold = $origin?->heldId === null ? null : $this->agentSignalHolds()->get($origin->heldId);
        $effect->stopPrefixId = $origin?->stopPrefixId;
        $effect->browserPrefixId = $origin?->browserPrefixId;
        if ($origin !== null) {
            if ($state->stop !== null && ($state->stop->includesDispatch($origin) || ($hold !== null && $state->stop->includesHold($hold)))) {
                $effect->stopPrefixId = $state->stop->id;
            }
            if ($state->browsers !== null && ($state->browsers->includesDispatch($origin)
                || ($hold !== null && $state->browsers->includesHold($hold)))) {
                $effect->browserPrefixId = $state->browsers->id;
            }
        }
        unset($hold);
        $cohortId = $origin?->effectCohortId;
        if ($cohortId === null || !isset($state->effectCohortCounts[$cohortId])) {
            $cohortId = ++$state->effectCohortSequence;
            $state->effectCohorts->add($cohortId);
        }
        if ($origin !== null) {
            $origin->effectCohortId = $cohortId;
        }
        $effect->effectCohortId = $cohortId;
        $state->effectCohortCounts[$cohortId] = ($state->effectCohortCounts[$cohortId] ?? 0) + 1;
        $state->effects[$effect->effectId] = $effect;
        $this->trackSignalDispatch($effect);
        return $effect;
    }

    /**
     * @param int $id Head finite lifecycle cohort
     * @throws Throwable When the retained effect or its terminal cleanup fails
     */
    private function advanceSignalEffect(int $id): void
    {
        $state = $this->signalDispatchState();
        $cohortId = $state->effects[$id]->effectCohortId;
        $this->trackSignalDispatch($state->effects[$id]);
        try {
            $this->advanceOwnedSignal($state->effects[$id]);
        } finally {
            if ($state->effects[$id] === null) {
                unset($state->effects[$id]);
                if ($cohortId !== null && --$state->effectCohortCounts[$cohortId] === 0) {
                    unset($state->effectCohortCounts[$cohortId]);
                    $state->effectCohorts->remove($cohortId);
                }
            }
        }
    }

    /**
     * Transfers completion ownership before removing a qualifying captured wait. A report
     * received after capture cannot make its earlier held destination disappear from the debt.
     *
     * @param ?SignalDispatch $effect Original effect receiving the obligation
     * @param AgentSignalHold $hold Original pending destination
     */
    public function captureSignalEffectHold(?SignalDispatch $effect, AgentSignalHold $hold): void
    {
        if ($effect?->effectId === null) {
            return;
        }
        $state = $this->signalDispatchState();
        if ($state->stop !== null && !$state->stop->complete && $state->stop->includesHold($hold)) {
            $effect->stopPrefixId = $state->stop->id;
            $state->stop->captureActive($effect);
        }
        if ($state->browsers !== null && !$state->browsers->complete && $state->browsers->includesHold($hold)) {
            $effect->browserPrefixId = $state->browsers->id;
            $state->browsers->captureActive($effect);
        }
    }

    /**
     * The guarded owner retains this lifetime until its one-time terminal phase finishes.
     *
     * @param SignalDTO $signal Original envelope
     * @param bool $wasParked Whether the identity wait already ended
     * @param WorkerServer $workerServer Owning worker server
     * @param ?WebSocketServer $webSocketServer Owning browser server
     * @param ?CommandServer $commandServer Console reply server
     * @param ?HttpServer $httpServer HTTP reply server
     * @param ?PeerServer $peerServer Peer transport of this node
     * @return SignalDispatch One signal's exact delivery lifetime
     */
    public function createSignalDispatch(
        SignalDTO $signal,
        bool $wasParked,
        WorkerServer $workerServer,
        ?WebSocketServer $webSocketServer,
        ?CommandServer $commandServer,
        ?HttpServer $httpServer,
        ?PeerServer $peerServer,
    ): SignalDispatch {
        $dispatch = new SignalDispatch($signal, fn(SignalDispatch $dispatch): Generator => $this->walkSignal(
            $dispatch, $wasParked, $workerServer, $webSocketServer, $commandServer, $httpServer, $peerServer,
        ), cleanup: $this->signalDeliveryCleanup());
        $dispatch->precedingHoldWatermark = $this->agentSignalHolds()->getWatermark();
        return $dispatch;
    }

    /**
     * Resolved local lookups are visible only to helpers executing this exact portion.
     * No current-dispatch context escapes a yield into other socket or manager work.
     *
     * @param SignalDispatch $dispatch Exact lifetime to advance
     * @param int $steps Maximum bounded steps
     * @throws Throwable When an existing delivery or terminal effect fails
     */
    public function runSignalPortion(SignalDispatch $dispatch, int $steps): void
    {
        $intake = $this->signalDispatchState?->intake;
        $before = $intake?->getWatermark();
        $hold = $dispatch->heldId === null ? null : $this->agentSignalHolds()->get($dispatch->heldId);
        $canceledHold = $hold !== null && $this->agentSignalHolds()->isCanceled($hold);
        $stop = $this->signalDispatchState?->stop;
        $stopOwned = $stop !== null && ($dispatch->stopPrefixId === $stop->id || ($hold !== null && $stop->includesHold($hold)));
        $browsers = $this->signalDispatchState?->browsers;
        $browserOwned = $browsers !== null && ($dispatch->browserPrefixId === $browsers->id || ($hold !== null && $browsers->includesHold($hold)));
        unset($hold);
        try {
            if ($canceledHold) {
                $dispatch->cancel();
            } elseif ($dispatch->groupOperation !== null && !$dispatch->groupOperation->isCurrent()) {
                $this->guardGroupOperation($dispatch);
            } elseif (!$this->host->mayContinueSignalDispatch($dispatch)) {
                $dispatch->cancel();
            } else {
                $dispatch->runPortion($steps);
            }
        } catch (Throwable $failure) {
            if ($stopOwned || ($stop?->includesDispatch($dispatch) ?? false)) {
                $stop->fail();
            }
            if ($browserOwned || ($browsers?->includesDispatch($dispatch) ?? false)) {
                $browsers->fail();
            }
            try {
                $dispatch->fail();
            } catch (Throwable) {
                // The original delivery failure remains the guard's failure after independent cleanup.
            }
            throw $failure;
        } finally {
            if ($intake !== null && $before !== null && $intake->router === Hilos::$sr) {
                $last = $intake->getWatermark();
                if ($stopOwned || ($stop !== null && $dispatch->stopPrefixId === $stop->id)) {
                    $stop->addChildren($intake, $before + 1, $last);
                }
                if ($browserOwned || ($browsers !== null && $dispatch->browserPrefixId === $browsers->id)) {
                    $browsers->addChildren($intake, $before + 1, $last);
                }
            }
        }
    }

    /**
     * The final send door also runs inside nested peer and predecessor continuations.
     *
     * @param SignalDispatch $dispatch Exact original delivery being guarded
     * @throws Throwable When canceling the stale lifetime encounters a disposal failure
     */
    public function guardGroupOperation(SignalDispatch $dispatch): void
    {
        if ($dispatch->groupOperation !== null && !$dispatch->groupOperation->isCurrent()) {
            $dispatch->cancel();
        }
    }

    /**
     * Every phase is entered once; the generator retains only this signal's unfinished work.
     *
     * @param SignalDispatch $dispatch Exact lifetime being advanced
     * @param bool $wasParked Whether to skip an already completed identity wait
     * @param WorkerServer $workerServer Worker transport
     * @param ?WebSocketServer $webSocketServer Browser transport
     * @param ?CommandServer $commandServer Console reply transport
     * @param ?HttpServer $httpServer HTTP reply transport
     * @param ?PeerServer $peerServer Peer transport
     * @return Generator<int, void> Ordered phase and destination steps, starting with an effect-free yield
     * @throws InvalidArgumentException When an internally produced signal cannot be named
     * @throws EnvException When destination configuration is invalid
     * @throws ClusterConfigurationException When a named-node destination cannot resolve this node
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     * @throws Throwable When preparation, delivery or terminal cleanup fails
     */
    private function walkSignal(
        SignalDispatch $dispatch,
        bool $wasParked,
        WorkerServer $workerServer,
        ?WebSocketServer $webSocketServer,
        ?CommandServer $commandServer,
        ?HttpServer $httpServer,
        ?PeerServer $peerServer,
    ): Generator {
        yield;
        $signal = $dispatch->getSignal();
        if ($signal === null || Hilos::$sr->isSignalApplicable($signal) === false) {
            return;
        }
        yield from $this->host->resolveSignalClient($dispatch, $webSocketServer);
        if ((yield from $this->host->prepareSignalDispatch(
            $dispatch, $wasParked, $workerServer, $webSocketServer, $peerServer,
        )) === SignalDispatchPhase::Terminal) {
            return;
        }
        $signal = $dispatch->getSignal();
        $dispatch->phase = SignalDispatchPhase::Destinations;
        $dispatch->destinations = Hilos::$sr->destinationCandidates($signal);
        yield;
        $this->host->beginSignalDestinations($dispatch);

        // Prepare application JSON lazily, once for the signal's whole local fan-out.
        $hasDestination = false;
        foreach ($dispatch->destinations as $destination) {
            if ($dispatch->skip) {
                break;
            }
            if ($destination === null) {
                yield;
                continue;
            }
            $hasDestination = true;
            $dispatch->physical = $this->walkSignalDestination($dispatch, $destination, $workerServer,
                $webSocketServer, $commandServer, $httpServer, $peerServer);
            yield from $dispatch->physical;
            if (!$dispatch->hasWork()) {
                return;
            }
            $dispatch->physical = null;
        }
        $dispatch->destinations = null;
        $this->host->finishUnservedSignal($dispatch, $hasDestination);

        $dispatch->phase = SignalDispatchPhase::Terminal;
        yield;
        yield from $this->host->finishSignalDispatch($dispatch, $workerServer);
    }

    /**
     * Addresses one destination once; its nested physical walk retains the prepared frame.
     *
     * @param SignalDispatch $dispatch Original signal's lifetime
     * @param Destination $destination One resolved target
     * @param WorkerServer $workerServer Worker transport
     * @param ?WebSocketServer $webSocketServer Browser transport
     * @param ?CommandServer $commandServer Console reply transport
     * @param ?HttpServer $httpServer HTTP reply transport
     * @param ?PeerServer $peerServer Peer transport
     * @return Generator<int, void> Effect-free destination boundary followed by physical visits
     * @throws InvalidArgumentException When an internally produced refusal cannot be named
     * @throws EnvException When agent placement configuration is invalid
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     * @throws Throwable When physical delivery or predecessor cleanup fails
     */
    private function walkSignalDestination(
        SignalDispatch $dispatch,
        Destination $destination,
        WorkerServer $workerServer,
        ?WebSocketServer $webSocketServer,
        ?CommandServer $commandServer,
        ?HttpServer $httpServer,
        ?PeerServer $peerServer,
    ): Generator {
        yield;
        $signal = $dispatch->getSignal();
        if ($signal === null) {
            return;
        }
        $signalName = $signal->signalName->getName();
        $signalType = $signal->signalType->getType();

        if ($destination instanceof WebSocketDestination || $destination instanceof AllClientsDestination
            || $destination instanceof SessionClientsDestination) {
            if ($webSocketServer !== null) {
                yield from $this->host->deliverSignalClient($dispatch, $webSocketServer, $destination);
            }
            return;
        }

        if ($destination instanceof AgentAddressedDestination) {
            // Counted as reached before anything is attempted, and in every case:
            // what the connection-close fan-out must not do twice is ADDRESS the same
            // agent, and a delivery that failed here has already been reported to the
            // browser by the branches below (HIL-745).
            $dispatch->agentsDelivered[] = $destination;

            // The delivery itself owes nobody an answer and asks for no placement - the
            // reactions to what it reports are this walk's, and the two callers that
            // reach the same agent from a subscription record have different ones.
            $outcome = yield from $this->agentDestinationDelivery($dispatch, $workerServer, $peerServer, $destination, $signal);
            switch ($outcome) {
                case AgentDeliveryOutcome::ShutdownSkipped:
                    $dispatch->skip = true;
                    break;
                case AgentDeliveryOutcome::RemoteUnreachable:
                    // A subscription waiting on the answer is told, and so is an operator
                    // waiting on a command; anything else stays dropped with the line the
                    // delivery already wrote.
                    $this->host->answerSignalAgentOutcome($dispatch, $signal, $outcome);
                    break;
                case AgentDeliveryOutcome::AddressUnknown:
                    // An agent that starts by being addressed is asked for first, so the
                    // address can come to exist (HIL-628), and this frame is held until the
                    // agent is up rather than dropped (HIL-629). The asker is answered when
                    // the wait ends - by the agent, or by the placement verdict that says
                    // nobody could host it (HIL-1041). There is no clock: a placement that
                    // takes longer than the old six seconds is still a placement, and a
                    // missing leader is waited out by asking again. The wait becomes a
                    // start-under-way wait the moment the agent turns up starting here.
                    // Held BEFORE the ask: on the leader the verdict of its own ask comes
                    // back in the same call stack, and it answers only frames already held.
                    $this->parkSignalAgent(
                        $dispatch, $signal, self::agentId($destination), $workerServer, true,
                    );
                    $this->host->requireSignalPlacement($dispatch, $destination->agentType, $destination->agentIndex);
                    break;
                case AgentDeliveryOutcome::StartRefused:
                    // The agent belongs here and did not come up; the node already wrote
                    // it down and told the project. The askers are answered as above, and
                    // the signal's other destinations go on: one agent costs only itself.
                    // No placement is asked for - the address is known, it is this node.
                    $this->host->answerSignalAgentOutcome($dispatch, $signal, $outcome);
                    break;
                case AgentDeliveryOutcome::Held:
                    // The door held the frame for an agent coming up on this node; like
                    // any other one agent, it does not stop the other destinations.
                    break;
                case AgentDeliveryOutcome::Delivered:
                    break;
            }
        } elseif ($destination instanceof RemoteClientDestination) {
            yield from $this->remoteClientSignalDelivery($peerServer, $destination, $signal);
        } elseif ($destination instanceof RemoteFanoutDestination) {
            // Carry the fan-out to the rest of the cluster. This marker travels
            // ALONGSIDE the local destinations, not instead of them: the browsers of
            // this node are served by those, and the other nodes are served by this one
            // frame, which each of them expands against its own subscription registry.
            if ($peerServer === null) {
                Logger::error("Peer client fanout dropped: {$signalType}/{$signalName} - no peer server");
                return;
            }

            yield from $peerServer->clientFanoutDelivery($signal);
        } elseif ($destination instanceof CommandReplyDestination) {
            yield from $this->host->deliverSignalCommandReply($dispatch, $destination, $commandServer, $peerServer);
        } elseif ($destination instanceof RemoteHttpReplyDestination) {
            // Carry the agent reply to the node that parked the connection. Same
            // best-effort contract as the client forward above: no live link drops and
            // logs, and the browser is left to its own timeout.
            if ($peerServer === null) {
                Logger::error("Peer HTTP reply dropped: {$signalType}/{$signalName}"
                    . " -> node {$destination->nodeId} - no peer server");
                return;
            }

            $delivered = false;
            yield from $peerServer->addressedDelivery($destination->nodeId,
                function () use ($peerServer, $destination, $signal, &$delivered): void {
                    $delivered = $peerServer->sendHttpReplyToNode($destination->nodeId, $signal);
                });
            if (!$delivered) {
                Logger::warning("Peer HTTP reply dropped: {$signalType}/{$signalName}"
                    . " -> node {$destination->nodeId} - no live link");
            }
        } elseif ($destination instanceof HttpReplyDestination) {
            // Write the agent reply to the connection this node parked for it
            if ($httpServer === null) {
                return;
            }

            $reply = $signal->data;
            if ($reply instanceof HttpReplyDTO) {
                $httpServer->deliver($destination->correlationId, $reply);
            }
        } else {
            // Unknown destination type, skip
            Logger::error("Unknown destination type: " . get_class($destination) . " for signal: {$signalType}/{$signalName}");
        }
    }

    /**
     * The stop effect already owns the local attempt. Only the resolved remote socket may
     * decide whether its exact token still authorizes a page recovery.
     *
     * @param ?PeerServer $peerServer Peer transport of the serving master
     * @param SignalDTO $signal Addressed page recovery control
     * @param string $acceptKey Connection that admitted the attempt
     * @param string $deliveryId Its original socket token
     * @return Generator<int, void> Existing routing and one bounded peer delivery
     * @throws EnvException When destination placement reads invalid cluster configuration
     * @throws ClusterConfigurationException When a named-node route cannot read its local node
     * @throws InvalidArgumentException When a moderation kind directory is malformed
     * @throws PeerTransportException When the recovery metadata is invalid for the signal
     */
    public function terminalPageRecoveryDelivery(?PeerServer $peerServer, SignalDTO $signal, string $acceptKey, string $deliveryId): Generator
    {
        foreach (Hilos::$sr->destinationCandidates($signal) as $destination) {
            if ($destination instanceof RemoteClientDestination && $destination->acceptKey === $acceptKey) {
                yield from $this->remoteClientSignalDelivery($peerServer, $destination, $signal, $deliveryId);
                return;
            }
            yield;
        }
    }

    /**
     * @param ?PeerServer $peerServer Peer transport of this master
     * @param RemoteClientDestination $destination Resolved socket address
     * @param SignalDTO $signal Addressed application signal
     * @param ?string $pageRecoveryDeliveryId Original socket token for terminal recovery
     * @return Generator<int, void> Bounded link lookup and one best-effort send
     * @throws PeerTransportException When the recovery metadata is invalid for the signal
     */
    private function remoteClientSignalDelivery(
        ?PeerServer $peerServer, RemoteClientDestination $destination, SignalDTO $signal, ?string $pageRecoveryDeliveryId = null,
    ): Generator {
        $address = $signal->signalType->getType() . '/' . $signal->signalName->getName()
            . " -> node {$destination->nodeId} client {$destination->acceptKey}";
        if ($peerServer === null) {
            Logger::error("Peer client signal dropped: {$address} - no peer server");
            return;
        }

        $delivered = false;
        $abandoned = false;
        yield from $peerServer->addressedDelivery($destination->nodeId, function () use (
            $peerServer, $destination, $signal, $pageRecoveryDeliveryId, &$delivered, &$abandoned,
        ): void {
            if ($destination->original !== null && !Hilos::$sr->isCurrentClientDestination($destination->original)) {
                $abandoned = true;
                return;
            }
            $delivered = $peerServer->sendSignalToClientNode($destination->nodeId, $destination->acceptKey, $signal, $pageRecoveryDeliveryId);
        });
        if (!$delivered && !$abandoned) {
            Logger::warning("Peer client signal dropped: {$address} - no live link");
        }
    }

    /**
     * @param SignalDispatch $dispatch Original delivery lifetime
     * @param WorkerServer $workerServer Worker transport
     * @param ?AgentSignalMesh $peerServer Peer transport
     * @param AgentAddressedDestination $destination One named agent
     * @param SignalDTO $signal Original envelope
     * @return Generator<int, void, void, AgentDeliveryOutcome> One existing delivery attempt and its domain outcome
     * @throws EnvException When delivery reads invalid cluster configuration
     * @throws Throwable When the delivery hook or predecessor cleanup fails
     */
    public function agentDestinationDelivery(
        SignalDispatch $dispatch,
        WorkerServer $workerServer,
        ?AgentSignalMesh $peerServer,
        AgentAddressedDestination $destination,
        SignalDTO $signal,
    ): Generator {
        yield;
        yield from $this->agentSignalPredecessors($dispatch, $workerServer, $destination);
        if (!$dispatch->hasWork() || !Hilos::$sr->isSignalApplicable($signal)) {
            return AgentDeliveryOutcome::Delivered;
        }
        $target = Hilos::$sr->isPlacementDestination($destination)
            ? new AgentDestination($destination->agentType, $destination->agentIndex) : null;
        if ($target !== null) {
            $destination = Hilos::$sr->placeAgentDestination($target);
        }
        while ($destination instanceof RemoteAgentDestination && $peerServer instanceof PeerServer) {
            $outcome = AgentDeliveryOutcome::Delivered;
            $next = null;
            yield from $peerServer->addressedDelivery($destination->nodeId, function () use (
                $dispatch, $workerServer, $peerServer, $destination, $signal, $target, &$next, &$outcome,
            ): void {
                if (!$dispatch->hasWork() || !Hilos::$sr->isSignalApplicable($signal)) {
                    return;
                }
                if ($target !== null) {
                    $current = Hilos::$sr->placeAgentDestination($target);
                    if (!$current instanceof RemoteAgentDestination || $current->nodeId !== $destination->nodeId) {
                        $next = $current;
                        return;
                    }
                }
                $outcome = $this->host->deliverSignalAgent($dispatch, $workerServer, $peerServer, $destination, $signal);
            });
            if ($next === null) {
                return $outcome;
            }
            $destination = $next;
        }
        return $this->host->deliverSignalAgent($dispatch, $workerServer, $peerServer, $destination, $signal);
    }

    /**
     * A bounded global discovery may not have reached an older wait yet. Visit that agent's
     * original predecessors before a newer addressed attempt; released priority runs between visits.
     *
     * @param SignalDispatch $dispatch Original delivery lifetime
     * @param WorkerServer $workerServer Existing placement/start owner
     * @param AgentAddressedDestination $destination Agent the newer attempt is addressing
     * @return Generator<int, void> Scalar predecessor probes, never the original signal's other destinations
     * @throws EnvException When existing placement configuration is invalid
     * @throws LogicException When a held obligation changes its addressee
     */
    private function agentSignalPredecessors(SignalDispatch $dispatch, WorkerServer $workerServer, AgentAddressedDestination $destination): Generator
    {
        $agentId = self::agentId($destination);
        if (isset($dispatch->predecessorsChecked[$agentId])) {
            return;
        }
        $dispatch->predecessorsChecked[$agentId] = true;
        $watermark = $dispatch->heldId !== null ? $dispatch->heldId - 1 : $dispatch->precedingHoldWatermark;
        $candidates = $this->agentSignalHolds()->agentCandidates($agentId, $watermark);
        yield from $this->probeAgentSignalHolds($workerServer, $candidates, $dispatch);
    }

    /**
     * The batch's original FIFO entry settles after all physical sends. Later input stays
     * behind it, and stop/browser prefixes account this one retained obligation in full.
     *
     * @param SignalDispatch $dispatch Original local batch lifetime
     * @param LocalClientSignalBatch $batch Shared frame and finite indexed transport admissions
     * @return Generator<int, void> Effect-free admission, then one preparation, physical visit or send step
     * @throws Throwable When original preparation, candidate source or admission callback fails
     */
    private function localClientSignalDelivery(SignalDispatch $dispatch, LocalClientSignalBatch $batch): Generator
    {
        yield;
        $dispatch->phase = SignalDispatchPhase::Destinations;
        while (!$batch->isComplete()) {
            $target = $batch->consumeTarget();
            if ($target !== null) {
                if (!$dispatch->framePrepared) {
                    $dispatch->frame = $this->host->prepareSignalFrame($batch->signal);
                    $dispatch->framePrepared = true;
                }
                if ($dispatch->frame !== null) {
                    yield from $this->host->deliverSignalTarget($dispatch, $target, $dispatch->frame, null);
                }
            }
            unset($target);
            yield;
        }
    }

    /**
     * @param string $originNodeId Node whose original fan-out is being delivered
     * @param SignalDTO $signal Already routed peer envelope
     * @param WebSocketServer $server Original local browser transport
     */
    public function queueClientFanout(string $originNodeId, SignalDTO $signal, WebSocketServer $server): void
    {

        $state = $this->signalDispatchState();
        $dispatch = $this->createClientFanoutDispatch($signal, $server);
        $dispatch->peerFanoutId = ++$state->peerSequence;
        $dispatch->peerOrigin = $originNodeId;
        $state->peers[$dispatch->peerFanoutId] = $dispatch;
    }

    /**
     * @param SignalDTO $signal Forwarded fan-out, already routed by its sending node
     * @param WebSocketServer $server Local browser server
     * @return SignalDispatch Local-only lifetime with subscriptions captured before its first portion
     */
    private function createClientFanoutDispatch(SignalDTO $signal, WebSocketServer $server): SignalDispatch
    {
        $destinations = Hilos::$sr->localClientCandidates($signal);
        return new SignalDispatch($signal, fn(SignalDispatch $dispatch): Generator =>
            $this->walkClientFanout($dispatch, $server, $destinations),
            cleanup: $this->signalDeliveryCleanup());
    }

    /**
     * This path never discovers agents or adds another mesh-forward marker.
     *
     * @param SignalDispatch $dispatch Original forwarded lifetime
     * @param WebSocketServer $server Exact local browser server
     * @param Generator<int, ?Destination> $destinations Captured local-only candidates
     * @return Generator<int, void> One-hop local destination and physical portions
     * @throws InvalidFormatException When a matching completion reads an invalid delivery window
     */
    private function walkClientFanout(SignalDispatch $dispatch, WebSocketServer $server, Generator $destinations): Generator
    {
        yield;
        $dispatch->phase = SignalDispatchPhase::Destinations;
        $dispatch->destinations = $destinations;
        foreach ($destinations as $destination) {
            if (!$destination instanceof WebSocketDestination && !$destination instanceof AllClientsDestination
                && !$destination instanceof SessionClientsDestination) {
                yield;
                continue;
            }
            $dispatch->physical = $this->host->deliverSignalClient($dispatch, $server, $destination);
            yield from $dispatch->physical;
            $dispatch->physical = null;
        }
    }

    /**
     * @param AgentSignalHold $hold Exact released obligation
     * @param WorkerServer $workerServer Worker transport
     * @param ?AgentSignalMesh $mesh Existing peer port, or null off-cluster
     * @return SignalDispatch One-agent continuation, never the original frame's other destinations
     * @throws LogicException When the selected obligation is not a released pending envelope
     */
    private function createReleasedAgentDispatch(AgentSignalHold $hold, WorkerServer $workerServer, ?AgentSignalMesh $mesh): SignalDispatch
    {
        if (!$hold->released || $hold->parked === null) {
            throw new LogicException('Agent signal hold has no released envelope');
        }
        return new SignalDispatch($hold->parked->signal,
            fn(SignalDispatch $dispatch): Generator => $this->walkReleasedAgentSignal($dispatch, $workerServer, $mesh),
            $hold->parked, $this->signalDeliveryCleanup());
    }

    /**
     * @param SignalDispatch $dispatch Exact one-agent release lifetime
     * @param WorkerServer $workerServer Worker transport
     * @param ?AgentSignalMesh $mesh Existing peer port
     * @return Generator<int, void> One addressed attempt with bounded physical peer lookup
     * @throws EnvException When placement configuration is invalid
     * @throws InvalidArgumentException When a refusal cannot be named
     * @throws LogicException When an obligation cannot be parked again
     * @throws Throwable When physical delivery or predecessor cleanup fails
     */
    private function walkReleasedAgentSignal(SignalDispatch $dispatch, WorkerServer $workerServer, ?AgentSignalMesh $mesh): Generator
    {
        yield;
        $now = microtime(true);
        $signal = $dispatch->getSignal();
        if ($signal === null || $dispatch->release === null) {
            return;
        }
        if ((yield from $this->host->prepareReleasedSignalDispatch($dispatch)) === SignalDispatchPhase::Terminal) {
            return;
        }
        $dispatch->phase = SignalDispatchPhase::Destinations;
        yield;
        $parked = $dispatch->release;
        if ($parked === null || !$dispatch->hasWork()) {
            return;
        }
        if ($parked->recovery === null && !$parked->linkBoundUpload
            && ($recovery = $this->signalHoldPlacement?->placementRecovery($parked->agentId)) !== null) {
            $this->agentSignalHolds()->retainRecovery($dispatch->heldId, $recovery);
            $parked = $dispatch->release;
        }
        $agent = AgentId::fromId($parked->agentId);
        Logger::info(sprintf(
            'Signal %s/%s held for agent %s let go after %.3fs',
            $signal->signalType->getType(),
            $signal->signalName->getName(),
            $parked->agentId,
            $now - $parked->parkedAt,
        ));

        if ($parked->recovery !== null) {
            $outcome = yield from $this->walkPlacementRecovery($dispatch, $workerServer, $mesh, $parked, $agent);
        } elseif ($parked->localOnly) {
            yield from $this->agentSignalPredecessors($dispatch, $workerServer, new AgentDestination($agent->type, $agent->index));
            $this->guardGroupOperation($dispatch);
            if (!$dispatch->hasWork()) {
                return;
            }
            $destination = Hilos::$sr->placeAgentDestination(new AgentDestination($agent->type, $agent->index));
            if ($destination instanceof RemoteAgentDestination) {
                $this->logPeerHoldOnOtherNode($parked, $destination);
                return;
            }
            $outcome = $destination instanceof AgentDestination
                ? $this->host->deliverLocalSignalAgent($dispatch, $workerServer, $destination, $signal)
                : AgentDeliveryOutcome::AddressUnknown;
        } else {
            $destination = Hilos::$sr->placeAgentDestination(new AgentDestination($agent->type, $agent->index));
            $outcome = yield from $this->agentDestinationDelivery($dispatch, $workerServer, $mesh, $destination, $signal);
        }
        if ($dispatch->getSignal() === null) {
            return;
        }
        switch ($outcome) {
            case AgentDeliveryOutcome::RemoteUnreachable:
                $this->host->answerSignalAgentOutcome($dispatch, $signal, $outcome);
                break;
            case AgentDeliveryOutcome::AddressUnknown:
                // A stop that took the agent off this node leaves no address yet, and that is
                // not a refusal: the next placement names a host, or the verdict says nobody
                // could. Answering here was the leftover of the six-second clock (HIL-1041).
                // Held before the ask, as in the walk: the leader's verdict answers in place.
                $this->parkSignalAgent($dispatch, $signal, $parked->agentId, $workerServer, true, $parked->localOnly);
                $this->host->requireSignalPlacement($dispatch, $agent->type, $agent->index);
                break;
            case AgentDeliveryOutcome::StartRefused:
                $this->host->answerSignalAgentOutcome($dispatch, $signal, $outcome);
                break;
            case AgentDeliveryOutcome::ShutdownSkipped:
            case AgentDeliveryOutcome::Held:
            case AgentDeliveryOutcome::Delivered:
                break;
        }
    }

    /**
     * @param SignalDispatch $dispatch Original addressed lifetime
     * @param WorkerServer $workers Local start and transport owner
     * @param ?AgentSignalMesh $mesh Outbound addressed peer port
     * @param ParkedAgentSignal $parked The same FIFO hold with its original replay basis
     * @param AgentId $agent Original addressed agent
     * @return Generator<int, void, void, AgentDeliveryOutcome> One confirmed delivery attempt, or the same hold parked again
     * @throws Throwable When predecessor, delivery or settlement work fails
     */
    private function walkPlacementRecovery(
        SignalDispatch $dispatch,
        WorkerServer $workers,
        ?AgentSignalMesh $mesh,
        ParkedAgentSignal $parked,
        AgentId $agent,
    ): Generator {
        $local = new AgentDestination($agent->type, $agent->index);
        yield from $this->agentSignalPredecessors($dispatch, $workers, $local);
        $this->guardGroupOperation($dispatch);
        if (!$dispatch->hasWork()) {
            return AgentDeliveryOutcome::Delivered;
        }

        if ($this->signalHoldPlacement?->isPlacementRecoveryCanceled($parked->agentId, $parked->recovery) ?? false) {
            return AgentDeliveryOutcome::StartRefused;
        }
        $location = $this->placementRecoveryLocation($workers, $parked, $agent);
        if ($location === null || $this->agentSignalHolds()->recoveryHead($parked->agentId)?->id !== $dispatch->heldId) {
            $this->reparkPlacementRecovery($dispatch, $workers, $parked);
            return AgentDeliveryOutcome::Held;
        }
        if ($location->kind === AgentLocationKind::Here) {
            return $parked->localOnly
                ? $this->host->deliverLocalSignalAgent($dispatch, $workers, $local, $parked->signal)
                : $this->host->deliverSignalAgent($dispatch, $workers, $mesh, $local, $parked->signal);
        }
        if ($parked->placementRecoveryRelay) {
            Logger::warning("Peer recovery signal dropped: agent {$parked->agentId} now runs on node {$location->nodeId}");
            return AgentDeliveryOutcome::StartRefused;
        }

        $destination = new RemoteAgentDestination($location->nodeId, $agent->type, $agent->index);
        if (!$mesh instanceof PeerServer) {
            return $this->host->deliverSignalAgent($dispatch, $workers, $mesh, $destination, $parked->signal);
        }

        $outcome = AgentDeliveryOutcome::Delivered;
        yield from $mesh->addressedDelivery($destination->nodeId,
            function () use ($dispatch, $workers, $mesh, $parked, $agent, $destination, &$outcome): void {
                $this->guardGroupOperation($dispatch);
                if (!$dispatch->hasWork()) {
                    return;
                }
                if ($this->signalHoldPlacement?->isPlacementRecoveryCanceled($parked->agentId, $parked->recovery) ?? false) {
                    $outcome = AgentDeliveryOutcome::StartRefused;
                    return;
                }
                // A predecessor may have reparked after this cursor passed its identity.
                if ($this->placementRecoveryLocation($workers, $parked, $agent)?->nodeId !== $destination->nodeId
                    || $this->agentSignalHolds()->recoveryHead($parked->agentId)?->id !== $dispatch->heldId) {
                    $this->reparkPlacementRecovery($dispatch, $workers, $parked);
                    $outcome = AgentDeliveryOutcome::Held;
                    return;
                }

                // The addressed peer queue owns the frame as soon as this call accepts it.
                $outcome = $this->host->deliverSignalAgent($dispatch, $workers, $mesh, $destination, $parked->signal);
            });
        return $outcome;
    }

    /**
     * @param WorkerServer $workers Existing local start owner
     * @param ParkedAgentSignal $parked Exact original obligation
     * @param AgentId $agent Addressed agent
     * @return ?AgentLocation Currently deliverable recovery destination
     */
    private function placementRecoveryLocation(WorkerServer $workers, ParkedAgentSignal $parked, AgentId $agent): ?AgentLocation
    {
        $location = $this->signalHoldPlacement?->startedPlacementRecovery($parked->agentId, $parked->recovery);
        if ($location?->kind === AgentLocationKind::Here
            && (!$workers->allowsPlacementRecovery($agent->type, $agent->index) || !$this->host->isSignalAgentStarted($parked->agentId))) {
            return null;
        }

        return $location;
    }

    /**
     * @param SignalDispatch $dispatch Active released hold
     * @param WorkerServer $workers Existing start owner
     * @param ParkedAgentSignal $parked Exact envelope, including its original replay basis and hop
     * @throws Throwable When the original hold cannot be parked again
     */
    private function reparkPlacementRecovery(SignalDispatch $dispatch, WorkerServer $workers, ParkedAgentSignal $parked): void
    {
        $holdId = $dispatch->heldId;
        $this->agentSignalHolds()->park($holdId, $parked);
        // A fact may have arrived while this cursor was active and invisible to its probe.
        if ($this->agentSignalHolds()->recoveryHead($parked->agentId)?->id === $holdId) {
            $this->queueAgentCandidate($holdId, $workers);
        }
    }

    /**
     * @param AgentAddressedDestination $destination Named agent address
     * @return string Existing agent identity representation
     */
    private static function agentId(AgentAddressedDestination $destination): string
    {
        return $destination->agentIndex === null ? $destination->agentType
            : $destination->agentType . AgentConstants::ID_SEPARATOR . $destination->agentIndex;
    }

    /**
     * Releases the exact bound owners, even when the facade already names a replacement router.
     *
     * @throws Throwable First probe disposal failure after all exact native bindings have detached
     */
    public function detachEventLoop(): void
    {
        $this->signalHoldGeneration++;
        if ($this->placementHoldWake !== null) {
            $this->signalHoldPlacement?->removeHoldWake($this->placementHoldWake);
        }
        if ($this->workerHoldWake !== null) {
            $this->signalHoldWorkerServer?->removeAgentHoldWake($this->workerHoldWake);
        }
        $this->signalHoldPlacement = null;
        $this->signalHoldWorkerServer = null;
        $this->placementHoldWake = null;
        $this->workerHoldWake = null;
        foreach ($this->placementSignalTimers as $timer) {
            $timer->close();
        }
        $this->placementSignalTimers = [];
        if ($this->signalDispatchNotifier !== null) {
            $this->signalDispatchRouter?->removeQueueWake($this->signalDispatchNotifier);
            $this->signalDispatchWorkerServer?->removeStopDispatchWake($this->signalDispatchNotifier);
            RtRowArena::process()->unbindPassWake($this->signalDispatchNotifier);
        }
        if ($this->sourceInterestDeliveryBinding !== null) {
            $this->signalDispatchPeerServer?->get()?->removeSourceInterestDelivery($this->sourceInterestDeliveryBinding);
        }
        if ($this->readerInterestDeliveryBinding !== null) {
            $this->signalDispatchAgentManager?->get()?->removeReaderInterestDelivery($this->readerInterestDeliveryBinding);
        }
        $this->signalDispatchRouter = null;
        $this->signalDispatchWorkerServer = null;
        $this->signalDispatchAgentManager = null;
        $this->signalDispatchPeerServer = null;
        $this->sourceInterestDeliveryBinding = null;
        $this->readerInterestDeliveryBinding = null;
        $this->eventLoop = null;
        $this->identitySignalTimer?->close();
        $this->identitySignalTimer = null;
        $this->signalDispatchWake?->close();
        $this->signalDispatchWake = null;
        $this->signalDispatchNotifier = null;
        $failure = null;
        try {
            if ($this->signalDispatchState !== null) {
                $this->signalDispatchState->agentProbe = null;
            }
        } catch (Throwable $disposalFailure) {
            $failure = $disposalFailure;
        }
        try {
            if ($this->signalDispatchState !== null) {
                $this->signalDispatchState->agentDiscoveries = [];
            }
        } catch (Throwable $disposalFailure) {
            $failure ??= $disposalFailure;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Rebinds exact local owners; only an actual owner change creates a finite recovery cohort.
     *
     * @param ?WorkerServer $workers Current local worker transport
     * @throws EnvException When held discovery reads invalid placement configuration
     * @throws EventLoopException When a held placement deadline cannot be armed
     */
    public function bindParkedSignalOwners(?WorkerServer $workers): void
    {
        $placement = Hilos::$cluster?->placement();
        if ($placement === $this->signalHoldPlacement && $workers === $this->signalHoldWorkerServer) {
            return;
        }
        if ($this->placementHoldWake !== null) {
            $this->signalHoldPlacement?->removeHoldWake($this->placementHoldWake);
        }
        if ($this->workerHoldWake !== null) {
            $this->signalHoldWorkerServer?->removeAgentHoldWake($this->workerHoldWake);
        }
        foreach ($this->placementSignalTimers as $timer) {
            $timer->close();
        }
        $this->placementSignalTimers = [];
        if ($this->signalDispatchState !== null) {
            $this->signalDispatchState->agentProbe = null;
            $this->signalDispatchState->agentDiscoveries = [];
        }
        $generation = ++$this->signalHoldGeneration;
        $owner = WeakReference::create($this);
        $this->placementHoldWake = static function (?string $agentId) use ($owner, $generation): void {
            $manager = $owner->get();
            if ($manager !== null && $manager->signalHoldGeneration === $generation) {
                $manager->queueAgentDiscovery($agentId, placementFact: true);
                $manager->host->noteSignalPlacementFact($agentId);
            }
        };
        $this->workerHoldWake = static function (?string $agentId) use ($owner, $generation): void {
            $manager = $owner->get();
            if ($manager !== null && $manager->signalHoldGeneration === $generation) {
                $manager->queueAgentDiscovery($agentId);
                $manager->host->noteSignalWorkerFact($agentId);
            }
        };
        $this->signalHoldPlacement = $placement;
        $this->signalHoldWorkerServer = $workers;
        $placement?->setHoldWake($this->placementHoldWake);
        $workers?->setAgentHoldWake($this->workerHoldWake);
        $this->queueAgentCandidates('binding', $this->agentSignalHolds()->candidates(), $workers);
    }

    /**
     * @param ?string $agentId Address whose start or placement changed; null for a basis or resume completion
     * @param bool $placementFact Whether a new placement basis permits a fresh on-demand ask
     */
    private function queueAgentDiscovery(?string $agentId = null, bool $placementFact = false): void
    {
        $key = ($placementFact ? 'placement:' : 'progress:') . ($agentId ?? 'all');
        if ($placementFact && $agentId === null) {
            $this->queueAgentCandidates('placement:replays', $this->agentSignalHolds()->resumeCandidates(), $this->signalHoldWorkerServer);
        }
        $candidates = $agentId === null
            ? ($placementFact ? $this->agentSignalHolds()->placementCandidates() : $this->agentSignalHolds()->resumeCandidates())
            : $this->agentSignalHolds()->agentCandidates($agentId);
        $this->queueAgentCandidates($key, $candidates, $this->signalHoldWorkerServer, $placementFact);
    }

    /**
     * @param int $holdId Exact obligation affected by admission, repark or predecessor settlement
     * @param ?WorkerServer $workers Existing local transport
     */
    private function queueAgentCandidate(int $holdId, ?WorkerServer $workers): void
    {
        $this->queueAgentCandidates('admission:' . $holdId, (static function () use ($holdId): Generator {
            yield $holdId;
        })(), $workers);
    }

    /**
     * @param string $key Coalescing identity of the actual fact or binding change
     * @param Generator<int, int> $candidates Captured original dependent obligations
     * @param ?WorkerServer $workers Exact local transport at the discovery fact
     * @param bool $placementFact Whether this fact invalidates an unresolved ask's suppression
     */
    private function queueAgentCandidates(string $key, Generator $candidates, ?WorkerServer $workers, bool $placementFact = false): void
    {
        if ($workers === null || ($this->agentSignalHolds?->getParkedCount() ?? 0) === 0) {
            return;
        }
        $state = $this->signalDispatchState();
        $state->agentDiscoveries[$key] ??= $this->probeAgentSignalHolds($workers, $candidates, placementFact: $placementFact);
        $this->signalDispatchWake?->markReady();
    }

    /**
     * Uses the existing ask owner's original suppression deadline, without a second retry window.
     *
     * @param string $agentId Address whose wait or ask actually changed
     * @throws EventLoopException When the original suppression deadline cannot be armed
     */
    public function rearmPlacementSignalTimer(string $agentId): void
    {
        if ($this->signalDispatchNotifier === null) {
            return;
        }
        $deadline = ($this->agentSignalHolds?->awaitsPlacement($agentId) ?? false)
            ? $this->signalHoldPlacement?->onDemandRetryAt($agentId) : null;
        if ($deadline === null) {
            if (isset($this->placementSignalTimers[$agentId])) {
                $this->placementSignalTimers[$agentId]->close();
                unset($this->placementSignalTimers[$agentId]);
            }
            return;
        }
        if (!isset($this->placementSignalTimers[$agentId])) {
            $owner = WeakReference::create($this);
            $wake = WeakReference::create($this->signalDispatchWake);
            $generation = $this->signalHoldGeneration;
            $incarnation = null;
            $this->placementSignalTimers[$agentId] = $this->eventLoop->createTimer(
                static function () use ($owner, $wake, $generation, $agentId, &$incarnation): void {
                    $dispatcher = $owner->get();
                    if ($dispatcher !== null && $dispatcher->signalHoldGeneration === $generation
                        && $wake->get() !== null && $dispatcher->signalDispatchWake === $wake->get()
                        && $incarnation?->get() !== null && ($dispatcher->placementSignalTimers[$agentId] ?? null) === $incarnation->get()) {
                        $dispatcher->queueAgentDiscovery($agentId);
                    }
                },
            );
            $incarnation = WeakReference::create($this->placementSignalTimers[$agentId]);
        }
        $timer = $this->placementSignalTimers[$agentId];
        $timer->armAt($deadline);
    }

    /**
     * One candidate per guarded step. A pending hold alone never marks native readiness.
     * All waits stay registered before an ask that can answer synchronously.
     *
     * @param WorkerServer $workerServer Existing start and protected-resume owner
     * @param Generator<int, int> $candidates Exact finite fact or predecessor cohort
     * @param ?SignalDispatch $preempted Active newer release, if a same-agent predecessor must go first
     * @param bool $placementFact Whether a new basis invalidates suppression for the unresolved addresses visited
     * @return Generator<int, void> Finite hold probes and their deduplicated placement asks
     * @throws EnvException When placement configuration is invalid
     * @throws LogicException When an existing obligation changes its addressee
     */
    private function probeAgentSignalHolds(WorkerServer $workerServer, Generator $candidates,
        ?SignalDispatch $preempted = null, bool $placementFact = false): Generator
    {
        yield;
        /** @var array<string, AgentId> One ask per unresolved agent after all waits are registered */
        $placementAsks = [];
        foreach ($candidates as $holdId) {
            $hold = $this->agentSignalHolds()->get($holdId);
            if ($hold !== null && $this->agentSignalHolds()->isCanceled($hold)) {
                $this->agentSignalHolds()->settle($holdId);
                unset($hold);
                yield;
                continue;
            }
            if ($hold === null || !$hold->isParked()) {
                if ($preempted !== null && ($hold?->released || ($preempted->heldId === null && $hold?->dispatch !== null))) {
                    $preempted->preemptHoldId = $holdId;
                }
                yield;
                continue;
            }
            $parked = $hold->parked;
            $agent = AgentId::fromId($parked->agentId);
            if ($parked->recovery === null && !$parked->linkBoundUpload
                && ($recovery = $this->signalHoldPlacement?->placementRecovery($parked->agentId)) !== null) {
                $parked = $parked->withRecovery($recovery);
                $this->agentSignalHolds()->park($holdId, $parked);
            }
            if ($parked->recovery !== null) {
                if (($this->signalHoldPlacement?->isPlacementRecoveryCanceled($parked->agentId, $parked->recovery) ?? false)
                    || ($this->agentSignalHolds()->recoveryHead($parked->agentId)?->id === $holdId
                        && $this->placementRecoveryLocation($workerServer, $parked, $agent) !== null)) {
                    $this->agentSignalHolds()->release($holdId);
                    if ($preempted !== null) {
                        $preempted->preemptHoldId = $holdId;
                    }
                }
                unset($parked, $hold);
                yield;
                continue;
            }

            $destination = Hilos::$sr->placeAgentDestination(new AgentDestination($agent->type, $agent->index));
            if ($destination instanceof RemoteAgentDestination) {
                if ($parked->localOnly) {
                    // A frame that already crossed the mesh does not cross it again: the sender
                    // named this node, and sending it on would put a third opinion about the host
                    // on the wire - two nodes that disagree would pass it back and forth. Dropped
                    // with a line, the way a forwarded frame whose start fails here is (HIL-1040).
                    $this->logPeerHoldOnOtherNode($parked, $destination);
                    $this->agentSignalHolds()->settle($holdId);
                    unset($parked, $hold);
                    yield;
                    continue;
                }

                $this->agentSignalHolds()->release($holdId);
                if ($preempted !== null) {
                    $preempted->preemptHoldId = $holdId;
                }
                unset($parked, $hold);
                yield;
                continue;
            }

            if ($parked->awaitingPlacementOrder) {
                if ($this->host->isSignalAgentStarted($parked->agentId)) {
                    $this->agentSignalHolds()->release($holdId);
                    if ($preempted !== null) {
                        $preempted->preemptHoldId = $holdId;
                    }
                    unset($parked, $hold);
                    yield;
                    continue;
                }

                if ($this->host->isAgentStartUnderWay($workerServer, $parked->agentId)) {
                    $parked = $parked->withoutPlacementOrderWait();
                } elseif (!$destination instanceof AgentDestination) {
                    $this->agentSignalHolds()->release($holdId);
                    if ($preempted !== null) {
                        $preempted->preemptHoldId = $holdId;
                    }
                    unset($parked, $hold);
                    yield;
                    continue;
                }
            }

            if ($parked->awaitingResume && !$workerServer->awaitsProtectedModeResume($agent->type, $agent->index)) {
                if ($this->host->isSignalAgentStarted($parked->agentId)) {
                    $this->agentSignalHolds()->release($holdId);
                    if ($preempted !== null) {
                        $preempted->preemptHoldId = $holdId;
                    }
                    unset($parked, $hold);
                    yield;
                    continue;
                }

                if ($this->host->isAgentStartUnderWay($workerServer, $parked->agentId)) {
                    $parked = $parked->withoutResumeWait();
                } else {
                    $signal = $parked->signal;
                    Logger::warning(
                        "Signal {$signal->signalType->getType()}/{$signal->signalName->getName()}"
                        . " held through the freeze for agent {$parked->agentId}"
                        . ' dropped: the lift did not bring it back to this node',
                    );
                    $this->agentSignalHolds()->settle($holdId);
                    unset($signal, $parked, $hold);
                    yield;
                    continue;
                }
            }

            if ($parked->awaitingPlacement && $this->host->isAgentStartUnderWay($workerServer, $parked->agentId)) {
                $parked = $parked->withoutPlacementWait();
            }

            if ($placementFact && $parked->awaitingPlacement && $destination instanceof AgentDestination) {
                $this->agentSignalHolds()->release($holdId);
                unset($parked, $hold);
                yield;
                continue;
            }

            if ($parked->awaitingPlacement) {
                $placementAsks[$parked->agentId] = $agent;
            }

            $this->agentSignalHolds()->park($holdId, $parked);
            unset($parked, $hold);
            yield;
        }

        foreach ($placementAsks as $agentId => $agent) {
            if (!$this->agentSignalHolds()->awaitsPlacement($agentId)) {
                yield;
                continue;
            }
            if ($placementFact) {
                $this->signalHoldPlacement?->invalidateOnDemandAsk($agentId);
            }
            $this->host->requireSignalPlacement($preempted, $agent->type, $agent->index);
            yield;
        }
    }

    /**
     * @param ParkedAgentSignal $parked Ordinary hold already forwarded over the mesh
     * @param RemoteAgentDestination $destination Current host of its agent
     */
    private function logPeerHoldOnOtherNode(ParkedAgentSignal $parked, RemoteAgentDestination $destination): void
    {
        $signal = $parked->signal;
        Logger::warning("Peer signal dropped: {$signal->signalType->getType()}/{$signal->signalName->getName()}"
            . " -> agent {$parked->agentId} turned up on node {$destination->nodeId} while the frame waited");
    }

    /**
     * @param string $collectionKey Applied runtime source
     * @param ?string $acceptKey Changed row, absent for a replacement of the connection source
     * @throws EventLoopException When identity discovery cannot rearm its original deadline
     */
    public function onIdentitySourceChanged(string $collectionKey, ?string $acceptKey = null): void
    {
        if ($this->parkedSignals !== [] && Hilos::$rt?->connectionsRegistry()?->getCollectionName() === $collectionKey) {
            $this->queueIdentityDiscovery($acceptKey);
        }
    }

    /**
     * @param ?string $acceptKey Exact dependent connection, absent for a deadline or context replacement
     * @param bool $deadline Whether this is the native expiry of the oldest original wait
     */
    private function queueIdentityDiscovery(?string $acceptKey = null, bool $deadline = false): void
    {
        if ($this->parkedSignals === []) {
            $this->identityDeadlinePending = false;
            return;
        }
        $state = $this->signalDispatchState();
        $key = $deadline ? 'deadline' : ($acceptKey === null ? 'all' : 'connection:' . $acceptKey);
        $state->identityDiscoveries[$key] ??= $this->probeIdentitySignals($acceptKey === null
            ? $this->identitySignals()->parkedCandidates() : $this->identitySignals()->connectionCandidates($acceptKey), $deadline);
        $this->signalDispatchWake?->markReady();
    }

    /**
     * Arms only the oldest original wait; monotonic park order is also deadline order.
     *
     * @throws EventLoopException When the native identity deadline cannot be armed
     */
    private function rearmIdentitySignalTimer(): void
    {
        if ($this->identitySignalTimer === null || $this->identityDeadlinePending) {
            return;
        }
        $first = $this->identitySignals?->firstParkedId();
        $this->identitySignalTimer->armAt($first === null ? null : $this->parkedSignals[$first]->deadline);
    }

    /**
     * @param ?Generator<int, int> $candidates Finite dependent cohort, absent for a direct whole-source check
     * @param bool $deadline Whether only the already-due original prefix needs discovery
     * @return Generator<int, void> One original identity waiter per visit
     */
    private function probeIdentitySignals(?Generator $candidates = null, bool $deadline = false): Generator
    {
        $candidates ??= $this->identitySignals()->parkedCandidates();
        yield;
        foreach ($candidates as $id) {
            $identity = $this->identitySignals()->get($id);
            if ($identity === null || $this->identitySignals()->isCanceled($identity)) {
                $this->identitySignals()->settle($id);
                unset($this->parkedSignals[$id], $identity);
                yield;
                continue;
            }
            unset($identity);
            $parked = $this->parkedSignals[$id] ?? null;
            if ($parked !== null) {
                $due = $parked->deadline <= hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
                if ($deadline && !$due) {
                    break;
                }
                $data = $parked->signal->data;
                $pending = false;
                if ($data instanceof WebSocketPageSubscribeSignalDTO) {
                    $page = $data->page ?? $parked->signal->signalName->getName();
                    $identityRouted = $data->deliveryId !== null
                        && Hilos::$sr?->pageAgentIndexRoute($page)?->source === PageAgentIndexSource::SESSION_USER_OR_GUEST;
                    $pending = $identityRouted ? PageHandoffIdentity::current($data->acceptKey) === null
                        : (Hilos::$browser?->connectionIdentity($data->acceptKey)->pending ?? false);
                }
                $identity = $this->identitySignals()->get($id);
                if ($identity !== null && !$this->identitySignals()->isCanceled($identity)
                    && (!$pending || $due)) {
                    if ($pending) {
                        Logger::error("Subscription of connection {$data->acceptKey} routed without its identity:"
                            . ' it did not arrive within ' . self::SUBSCRIPTION_IDENTITY_WAIT_TIMEOUT_MS . 'ms');
                    }
                    $state = $this->signalDispatchState();
                    $state->identities[++$state->identitySequence] = $parked->signal;
                    $this->identitySignals()->release($id, $state->identitySequence);
                    unset($this->parkedSignals[$id]);
                    $this->rearmIdentitySignalTimer();
                    $this->signalDispatchWake?->markReady();
                }
            }
            unset($data, $parked, $identity);
            yield;
        }
        if ($deadline) {
            $this->identityDeadlinePending = false;
        }
        $this->rearmIdentitySignalTimer();
    }

    /** @return AgentSignalHolds Sole payload owner for this master's agent waits */
    public function agentSignalHolds(): AgentSignalHolds
    {
        return $this->agentSignalHolds ??= new AgentSignalHolds(function (AgentSignalHold $hold, ?int $nextRecoveryId): void {
            $this->signalDispatchState?->stop?->noteHoldChanged($hold);
            $this->signalDispatchState?->browsers?->noteHoldChanged($hold);
            if ($nextRecoveryId !== null && $this->agentSignalHolds()->get($nextRecoveryId)?->isParked()) {
                $this->queueAgentCandidate($nextRecoveryId, $this->signalHoldWorkerServer);
            }
            $this->rearmPlacementSignalTimer($hold->agentId);
            $this->signalDispatchWake?->markReady();
        });
    }

    /** @return IdentitySignalIndex Scalar index over this master's original parked and ready arrays */
    public function identitySignals(): IdentitySignalIndex
    {
        return $this->identitySignals ??= new IdentitySignalIndex(function (IdentitySignalEntry $entry): void {
            unset($this->parkedSignals[$entry->id]);
            if ($entry->releaseId !== null && $this->signalDispatchState !== null) {
                unset($this->signalDispatchState->identities[$entry->releaseId]);
                $this->signalDispatchState->stop?->noteIdentitySettled($entry->releaseId);
                $this->signalDispatchState->browsers?->noteIdentitySettled($entry->releaseId);
            }
            $this->rearmIdentitySignalTimer();
        });
    }

    /** @return PendingPageIndex Scalar indexes over the original process-private pending payload ledger */
    public function pendingPageIndex(): PendingPageIndex
    {
        return $this->pendingPageIndex ??= new PendingPageIndex(
            fn(string $acceptKey, string $deliveryId): ?PendingPageDelivery => $this->pendingPageDeliveries[$acceptKey][$deliveryId] ?? null,
            function (PendingPageEntry $entry): void {
                unset($this->pendingPageDeliveries[$entry->acceptKey][$entry->deliveryId]);
                if (($this->pendingPageDeliveries[$entry->acceptKey] ?? []) === []) {
                    unset($this->pendingPageDeliveries[$entry->acceptKey]);
                }
            },
        );
    }

    /**
     * Holds a frame for one agent that is not up yet, instead of handing it to nobody (HIL-629).
     *
     * Three doors lead here and they wait on different things (HIL-1041, HIL-1208). The local
     * delivery door holds for an agent whose start is under way on this node: that wait ends on a
     * fact the node is going to hear, and a clock over it would answer the asker while the start
     * it is waiting for is still running. The same door holds a connection's close for an agent
     * the protected-mode freeze stopped, until the lift brings it back. The walk holds for an
     * agent no node is known to host and marks the hold as waiting on a placement verdict,
     * because that is the fact that is coming. In every case the frame is let go by
     * the dispatcher's released-agent traversal.
     *
     * @param ?SignalDispatch $dispatch Exact causal owner; null is a root
     * @param SignalDTO $signal Signal the walk was delivering
     * @param string $agentId Agent it waits for
     * @param ?WorkerServer $workers Current local worker transport
     * @param bool $awaitingPlacement Whether the wait is for a placement verdict rather than a start here
     * @param bool $localOnly Whether the frame arrived already addressed over the peer mesh
     * @param bool $awaitingResume Whether the frame is a connection's close waiting for the lift to bring its agent back
     * @param bool $awaitingPlacementOrder Whether the leader's order to start the agent here has not arrived
     * @param ?PlacementRecovery $recovery Recovery proof already selected by the shared admission door
     * @throws LogicException When a retained obligation changes its addressed agent
     * @throws EnvException When held-agent discovery reads invalid placement configuration
     * @throws EventLoopException When a placement retry deadline cannot be armed
     */
    public function parkSignalAgent(
        ?SignalDispatch $dispatch,
        SignalDTO $signal,
        string $agentId,
        ?WorkerServer $workers,
        bool $awaitingPlacement = false,
        bool $localOnly = false,
        bool $awaitingResume = false,
        bool $awaitingPlacementOrder = false,
        ?PlacementRecovery $recovery = null,
    ): void {
        $payload = $signal->data;
        if ($dispatch !== null && !$dispatch->hasWork()
            && ($payload instanceof CommandRequestDTO || $payload instanceof HttpRequestDTO)
            && isset($this->signalDispatchState->abandonedCommands[$payload->correlationId])) {
            return;
        }
        $recovery = $dispatch?->linkBoundUpload ? null
            : ($dispatch?->release?->recovery ?? $recovery ?? Hilos::$cluster?->placement()?->placementRecovery($agentId));
        $parked = new ParkedAgentSignal(
            $signal,
            $agentId,
            $dispatch?->release?->parkedAt ?? microtime(true),
            $awaitingPlacement && $recovery === null,
            $localOnly,
            $awaitingResume && $recovery === null,
            $awaitingPlacementOrder && $recovery === null,
            $dispatch?->getSignal() === $signal ? $dispatch->groupOperation : null,
            $recovery,
            $dispatch?->placementRecoveryRelay ?? false,
            $dispatch?->linkBoundUpload ?? false,
        );
        $existing = $dispatch?->heldId;
        if ($existing !== null && $this->agentSignalHolds()->get($existing)?->agentId === $agentId) {
            $this->agentSignalHolds()->park($existing, $parked);
            $holdId = $existing;
        } else {
            $holdId = $this->agentSignalHolds()->add($parked, $dispatch?->stopPrefixId,
                $dispatch?->browserPrefixId)->id;
        }
        if (!$parked->awaitingPlacement) {
            $this->queueAgentCandidate($holdId, $workers);
        }
    }

    /**
     * Forgets undelivered command or HTTP frames, including those a start report already released.
     *
     * Silently, unlike every other way a hold ends: the refusal the other endings write is
     * addressed to the caller, and here the caller is precisely what has gone. A page's held
     * subscribe is dropped the same way and for the same reason
     * (DaemonManager::cancelPendingPageDeliveries).
     *
     * @param string $correlationId Correlation id of the request that was given up on
     * @param ?SignalDispatch $origin Original current lifetime, absent for a root
     * @throws Throwable When the initial cancellation effect fails
     */
    public function dropParkedCommandSignals(string $correlationId, ?SignalDispatch $origin): void
    {
        $state = $this->signalDispatchState();
        $inputTail = $state->admissionSequence + $state->retiredRemaining + $state->intake->getWatermark() - $state->intake->consumed;
        $cancellation = new CommandSignalCancellation(++$state->commandCancellationSequence, $correlationId, $inputTail);
        $state->commandCancellations[$cancellation->id] = $cancellation;
        $state->abandonedCommands[$correlationId] = $cancellation;
        $cleanup = $this->agentSignalHolds()->cancelCorrelation($correlationId);
        // Three exact executing slots; suspended releases remain indexed under their holds.
        foreach ([$state->active, $state->released, $origin] as $dispatch) {
            $payload = $dispatch?->getSignal()?->data;
            if (($payload instanceof CommandRequestDTO || $payload instanceof HttpRequestDTO) && $payload->correlationId === $correlationId) {
                $dispatch->cancel();
            }
        }
        unset($payload);
        $effect = $this->queueSignalEffect(static function () use ($cleanup, $cancellation): Generator {
            yield from $cleanup;
            $cancellation->holdsComplete = true;
        }, $origin);
        if ($origin === null || $origin->isDirect()) {
            $this->advanceInitialSignalEffect($effect);
        }
        $this->markReady();
    }

    /** @return DeliveryCleanup Existing guarded effects, without a strong callback retaining this manager */
    public function deliveryCleanup(): DeliveryCleanup
    {
        if ($this->deliveryCleanup === null) {
            $manager = WeakReference::create($this);
            $this->deliveryCleanup = new DeliveryCleanup(static function (Generator $cleanup, ?SignalDispatch $origin = null) use ($manager): void {
                $owner = $manager->get();
                if ($owner !== null) {
                    $effect = $owner->queueSignalEffect(static fn(SignalDispatch $dispatch): Generator => $cleanup, $origin);
                    if ($origin === null || $origin->isDirect()) {
                        $owner->advanceInitialSignalEffect($effect);
                    }
                    $owner->markReady();
                } else {
                    foreach ($cleanup as $_) {
                    }
                }
            });
        }
        return $this->deliveryCleanup;
    }

    /** @return ?DeliveryCleanup Cleanup scheduler only while native dispatch is attached */
    public function signalDeliveryCleanup(): ?DeliveryCleanup
    {
        return !$this->isAttached() ? null : $this->deliveryCleanup();
    }

    /**
     * Native callbacks cue the guarded phase; they never advance a signal.
     *
     * @param EventLoop $eventLoop Exact native base
     * @param ?WorkerServer $workers Current stop and held-agent transport
     * @param AgentManagerDaemon $agents Current reader-interest source
     * @param ?PeerServer $peers Current peer-interest source
     * @throws EventLoopException When native dispatch or an original deadline cannot be armed
     * @throws EnvException When held discovery reads invalid placement configuration
     * @throws Throwable When replacement probe disposal fails
     */
    public function attachEventLoop(EventLoop $eventLoop, ?WorkerServer $workers, AgentManagerDaemon $agents, ?PeerServer $peers): void
    {
        $this->detachEventLoop();
        $this->eventLoop = $eventLoop;
        $this->signalDispatchWake = $eventLoop->createReadyWork(static function (): void {
            // The guarded dispatch phase follows source announcements in this iteration.
        });
        $this->signalDispatchNotifier = $this->signalDispatchWake->markReady(...);
        RtRowArena::process()->bindPassWake($this->signalDispatchNotifier);
        $this->signalDispatchState();
        $this->bindSignalDispatchRouter();
        $owner = WeakReference::create($this);
        $wake = WeakReference::create($this->signalDispatchWake);
        $this->identitySignalTimer = $eventLoop->createTimer(static function () use ($owner, $wake): void {
            $dispatcher = $owner->get();
            if ($dispatcher !== null && $wake->get() !== null && $dispatcher->signalDispatchWake === $wake->get()) {
                $dispatcher->identityDeadlinePending = true;
                $dispatcher->queueIdentityDiscovery(deadline: true);
            }
        });
        $this->rearmIdentitySignalTimer();
        $this->signalDispatchWorkerServer = $workers;
        $workers?->setStopDispatchWake($this->signalDispatchNotifier);
        $this->bindSourceInterestDeliverySources($agents, $peers);
        $this->bindParkedSignalOwners($workers);
        $this->wakeRemainder($workers);
    }

    /**
     * @param AgentManagerDaemon $agents Exact current report owner
     * @param ?PeerServer $peers Exact current peer transport
     */
    public function bindSourceInterestDeliverySources(AgentManagerDaemon $agents, ?PeerServer $peers): void
    {
        if (!$this->isAttached()) {
            return;
        }
        if ($this->signalDispatchAgentManager?->get() !== $agents) {
            if ($this->readerInterestDeliveryBinding !== null) {
                $this->signalDispatchAgentManager?->get()?->removeReaderInterestDelivery($this->readerInterestDeliveryBinding);
            }
            $this->signalDispatchAgentManager = WeakReference::create($agents);
            $this->readerInterestDeliveryBinding = $this->sourceInterestDelivery($agents);
            $agents->setReaderInterestDelivery($this->readerInterestDeliveryBinding);
        }
        if ($this->signalDispatchPeerServer?->get() !== $peers) {
            if ($this->sourceInterestDeliveryBinding !== null) {
                $this->signalDispatchPeerServer?->get()?->removeSourceInterestDelivery($this->sourceInterestDeliveryBinding);
            }
            $this->signalDispatchPeerServer = $peers === null ? null : WeakReference::create($peers);
            $this->sourceInterestDeliveryBinding = $peers === null ? null : $this->sourceInterestDelivery($peers);
            if ($this->sourceInterestDeliveryBinding !== null) {
                $peers->setSourceInterestDelivery($this->sourceInterestDeliveryBinding);
            }
        }
    }

    /**
     * @param AgentManagerDaemon|PeerServer $source Original source incarnation
     * @return Closure(Closure(SignalDispatch): Generator<int, void>): void Weak observer confined to its exact source and native attachment
     */
    private function sourceInterestDelivery(AgentManagerDaemon|PeerServer $source): Closure
    {
        $owner = WeakReference::create($this);
        $wake = WeakReference::create($this->signalDispatchWake);
        $incarnation = WeakReference::create($source);
        $binding = null;
        $callback = static function (Closure $delivery) use ($owner, $wake, $incarnation, &$binding): void {
            $dispatcher = $owner->get();
            $source = $incarnation->get();
            if ($dispatcher === null || $source === null || $wake->get() === null
                || $dispatcher->signalDispatchWake !== $wake->get() || !$dispatcher->host->acceptsSignalWork()) {
                return;
            }
            if (($source instanceof AgentManagerDaemon && $dispatcher->signalDispatchAgentManager?->get() === $source
                    && $dispatcher->readerInterestDeliveryBinding === $binding?->get())
                || ($source instanceof PeerServer && $dispatcher->signalDispatchPeerServer?->get() === $source
                    && $dispatcher->sourceInterestDeliveryBinding === $binding?->get())) {
                $effect = $dispatcher->queueSignalEffect($delivery, null);
                $dispatcher->advanceInitialSignalEffect($effect);
                $dispatcher->markReady();
            }
        };
        $binding = WeakReference::create($callback);
        return $callback;
    }

    /** Moves only this observer's cue; neither router FIFO is consumed here. */
    private function bindSignalDispatchRouter(): void
    {
        $this->host->noteSignalRouterChanged();
        if ($this->signalDispatchNotifier === null || $this->signalDispatchRouter === Hilos::$sr) {
            return;
        }
        $this->signalDispatchRouter?->removeQueueWake($this->signalDispatchNotifier);
        $this->signalDispatchRouter = Hilos::$sr;
        $this->signalDispatchRouter?->setQueueWake($this->signalDispatchNotifier);
    }

    /** @return bool Whether native dispatch currently owns a wake token */
    public function isAttached(): bool
    {
        return $this->signalDispatchNotifier !== null;
    }

    /** Cues only the existing guarded master phase. */
    public function markReady(): void
    {
        $this->signalDispatchWake?->markReady();
    }

    /** @param ?WorkerServer $workers Current local transport for the runnable predicate */
    public function wakeRemainder(?WorkerServer $workers): void
    {
        $this->signalDispatchWake?->cancel();
        if ($this->signalDispatchWake !== null && $this->hasRunnableSignalDispatch($workers)) {
            $this->signalDispatchWake->markReady();
        }
    }

    /**
     * Reconciles identity incarnations only in the guarded dispatch phase.
     *
     * @throws EventLoopException When identity discovery cannot rearm its original deadline
     */
    public function prepareDispatch(): void
    {
        $this->signalDispatchWake?->cancel();
        if ($this->identityRuntime?->get() !== Hilos::$rt || $this->identityBrowser?->get() !== Hilos::$browser) {
            $this->identityRuntime = Hilos::$rt === null ? null : WeakReference::create(Hilos::$rt);
            $this->identityBrowser = Hilos::$browser === null ? null : WeakReference::create(Hilos::$browser);
            $this->queueIdentityDiscovery();
        }
    }

    /** @param ?string $agentId Changed placement address, absent for a new placement basis */
    public function notePlacementFact(?string $agentId): void
    {
        $this->placementHoldWake?->__invoke($agentId);
    }

    /**
     * @param SignalDTO $signal Original identity-dependent signal
     * @param string $acceptKey Original connection
     * @param string $page Original page
     * @throws EventLoopException When the original identity deadline cannot be armed
     */
    public function parkIdentitySignal(SignalDTO $signal, string $acceptKey, string $page): void
    {
        $this->parkedSignals[++$this->parkedSignalSequence] = new ParkedSignal(
            $signal,
            hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND
                + self::SUBSCRIPTION_IDENTITY_WAIT_TIMEOUT_MS / TimeConstants::MS_PER_SECOND,
        );
        $this->identitySignals()->add($this->parkedSignalSequence, $acceptKey, $page);
        $this->rearmIdentitySignalTimer();
    }

    /** @param PendingPageDelivery $pending Exact attempt and its sole payload owner */
    public function recordPendingPageDelivery(PendingPageDelivery $pending): void
    {
        $this->pendingPageIndex()->record($pending);
        $this->pendingPageDeliveries[$pending->acceptKey][$pending->deliveryId] = $pending;
    }

    /** @param SignalDispatch $dispatch Original failed lifetime */
    public function failPrefixes(SignalDispatch $dispatch): void
    {
        if ($this->signalDispatchState?->stop?->includesDispatch($dispatch)) {
            $this->signalDispatchState->stop->fail();
        }
        if ($this->signalDispatchState?->browsers?->includesDispatch($dispatch)) {
            $this->signalDispatchState->browsers->fail();
        }
    }

    /**
     * @throws Throwable First disposal failure after every independent retained owner has cleared
     */
    public function discard(): void
    {
        $this->deliveryCleanup?->detach();
        $failure = null;
        try {
            $this->detachEventLoop();
        } catch (Throwable $disposalFailure) {
            $failure = $disposalFailure;
        }
        try {
            $this->discardState();
        } catch (Throwable $disposalFailure) {
            $failure ??= $disposalFailure;
        }
        try {
            $this->agentSignalHolds?->discard();
        } catch (Throwable $disposalFailure) {
            $failure ??= $disposalFailure;
        }
        foreach (['agentSignalHolds', 'identitySignals', 'pendingPageIndex', 'identityRuntime', 'identityBrowser'] as $field) {
            try {
                $this->$field = null;
            } catch (Throwable $disposalFailure) {
                $failure ??= $disposalFailure;
            }
        }
        foreach (['parkedSignals', 'pendingPageDeliveries'] as $field) {
            try {
                $this->$field = [];
            } catch (Throwable $disposalFailure) {
                $failure ??= $disposalFailure;
            }
        }
        $this->identityDeadlinePending = false;
        if ($failure !== null) {
            throw $failure;
        }
    }
}
