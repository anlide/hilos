<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Hilos\Cluster\NodeName;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Cluster\Peer\DTO\PeerAgentRestartDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\DaemonSection\DaemonRestartingAgent;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\HilosException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Command\CommandReplyOrigins;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Logger;
use LogicException;
use Throwable;

/** Master-owned open agent restarts and their command-channel waiters. */
final class AgentRestarts
{
    /** @var array<string, AgentRestartRun> Agent id to open restart */
    private array $runs = [];

    private int $revision = 0;

    /** Nearest live caller deadline; an open restart without callers has no timer duty. */
    private float $nextWaitDeadline = INF;

    private ?EventLoopTimer $deadlineTimer = null;

    /**
     * @param AgentManagerDaemon $agents This master's agent roster
     * @param Closure(): ?WorkerServer $workerServer Current local worker server
     * @param CommandReplyOrigins $replyOrigins Return path for a request from another node
     * @param ?Closure(): ?PeerServer $peerServer Current peer transport, null in unit tests
     * @param ?Closure(string, PeerAgentRestartDTO): bool $sendRestartFrame Test port for peer delivery
     */
    public function __construct(
        private readonly AgentManagerDaemon $agents,
        private readonly Closure $workerServer,
        private readonly CommandReplyOrigins $replyOrigins,
        private readonly ?Closure $peerServer = null,
        private readonly ?Closure $sendRestartFrame = null,
    ) {
    }

    /**
     * Native readiness only cues the manager's guarded deadline phase.
     *
     * @param EventLoop $loop This master's native event loop
     * @param Closure(): void $wake Cue the guarded deadline phase
     * @throws EventLoopException When an original live deadline cannot be armed
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        $this->detachEventLoop();
        $this->deadlineTimer = $loop->createTimer($wake);
        $this->rearmDeadlineTimer();
    }

    /** Releases this binding's native and queued work, preserving live callers for rebind. */
    public function detachEventLoop(): void
    {
        $this->deadlineTimer?->close();
        $this->deadlineTimer = null;
    }

    /** @return int Open-restart revision */
    public function revision(): int
    {
        return $this->revision;
    }

    /**
     * @return list<DaemonRestartingAgent> Open restarts sorted by id
     * @throws InvalidFormatException When an open record carries an invalid identity
     */
    public function restartingAgents(): array
    {
        $pictures = [];
        foreach ($this->runs as $run) {
            $pictures[] = new DaemonRestartingAgent($run->id, $run->nodeId);
        }
        usort($pictures, static fn (DaemonRestartingAgent $a, DaemonRestartingAgent $b): int => strcmp($a->id, $b->id));

        return $pictures;
    }

    /**
     * Validates an operator request and addresses the replica, leader, or local worker.
     *
     * @param CommandRequestDTO $request Held operator request
     * @param ?float $now Monotonic seconds, injectable for the wait-window test
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function request(CommandRequestDTO $request, ?float $now = null): void
    {
        try {
            $this->requestChecked($request, $now ?? hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND);
        } catch (EventLoopException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            Logger::error('Agent restart request failed before stop: ' . $failure->getMessage());
            $type = $request->payload[CommandConstants::FIELD_AGENT_TYPE] ?? null;
            $label = is_string($type) && $type !== '' ? $type : get_debug_type($type);
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'No node can take %s now; it was not stopped', $label,
            )));
        }
    }

    /**
     * @param CommandRequestDTO $request Held operator request
     * @param float $now Monotonic seconds
     * @throws ClusterConfigurationException When the cluster node configuration cannot be read
     * @throws ClusterDisabledException When a cluster-only lookup has no cluster
     * @throws EnvException When a cluster setting cannot be read
     * @throws AgentDaemonCreationFailedException When a placement profile cannot be built
     * @throws HilosException When the project cannot describe a placement candidate
     * @throws LogicException When an agent declares a negative resource cost or the reserved slot
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    private function requestChecked(CommandRequestDTO $request, float $now): void
    {
        $type = $request->payload[CommandConstants::FIELD_AGENT_TYPE] ?? null;
        $index = $request->payload[CommandConstants::FIELD_AGENT_INDEX] ?? null;
        $askedNodeId = $request->payload[CommandConstants::FIELD_NODE_ID] ?? null;
        if (!is_string($type) || $type === '') {
            $label = is_string($type) ? '(empty)' : get_debug_type($type);
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'No agent type %s in this installation', $label,
            )));
            return;
        }
        $registry = Hilos::appClass()::AGENTS[$type] ?? null;
        if ($registry === null) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'No agent type %s in this installation', $type,
            )));
            return;
        }
        if (AgentRegistry::requiresIndex($registry) && (!is_string($index) || $index === '')) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Agent type %s needs an index: %s:<index>', $type, $type,
            )));
            return;
        }
        if (!AgentRegistry::requiresIndex($registry) && $index !== null) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Agent type %s takes no index', $type,
            )));
            return;
        }
        $id = $index === null ? $type : $type . AgentConstants::ID_SEPARATOR . $index;

        $localNodeId = Hilos::$cluster?->localNodeId() ?? HilosClusterNode::STANDALONE_NODE_ID;
        if ($askedNodeId !== null && (!is_string($askedNodeId) || $askedNodeId === '')) {
            $label = is_string($askedNodeId) ? '(empty)' : get_debug_type($askedNodeId);
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'No node %s in this installation', $label,
            )));
            return;
        }
        if (Hilos::$cluster?->isEnabled() !== true && $askedNodeId !== null && $askedNodeId !== $localNodeId) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'No node %s in this installation', $askedNodeId,
            )));
            return;
        }

        if (AgentRegistry::scope($registry) === AgentScope::NODE && $askedNodeId !== null && $askedNodeId !== $localNodeId) {
            if (!$this->nodeKnown($askedNodeId)) {
                $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                    'No node %s in this installation', $askedNodeId,
                )));
                return;
            }
            if (!$this->nodeOnline($askedNodeId)) {
                $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                    'Node %s is silent', NodeName::label($askedNodeId, Hilos::$rt?->hilosClusterNodes[$askedNodeId]?->name),
                )));
                return;
            }
            $this->forward($request, $type, $index, $askedNodeId, false, false);
            return;
        }
        if (AgentRegistry::scope($registry) === AgentScope::CLUSTER && Hilos::$cluster?->isEnabled() === true) {
            $leaderId = Hilos::$cluster->placement()?->knownLeaderId();
            if ($leaderId === null) {
                $this->reply($request, CommandReplyDTO::error($request->correlationId, 'The cluster has no leader right now'));
                return;
            }
            if ($leaderId !== $localNodeId) {
                if (!$this->nodeOnline($leaderId)) {
                    $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                        'The leader %s is unreachable', $leaderId,
                    )));
                    return;
                }
                $this->forward($request, $type, $index, $leaderId, false, true);
                return;
            }
            $this->executeAsLeader($request, $type, $index, $id, $localNodeId, $now);
            return;
        }

        $this->executeHere($request, $type, $index, $id, $localNodeId, false, $now);
    }

    /**
     * An authenticated peer request is a final destination: never route it to another peer.
     *
     * @param PeerAgentRestartDTO $frame Peer request with the caller's return address
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function fromPeer(PeerAgentRestartDTO $frame): void
    {
        $request = new CommandRequestDTO(
            $frame->correlationId,
            CliCommands::AGENT_RESTART,
            [
                CommandConstants::FIELD_AGENT_TYPE => $frame->agentType,
                CommandConstants::FIELD_AGENT_INDEX => $frame->agentIndex,
                CommandConstants::FIELD_NODE_ID => $frame->nodeId,
            ],
            $frame->originNodeId,
        );
        try {
            $this->fromPeerChecked($request, $frame);
        } catch (EventLoopException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            Logger::error('Peer agent restart request failed before stop: ' . $failure->getMessage());
            $this->reply($request, CommandReplyDTO::error($frame->correlationId, sprintf(
                'No node can take %s now; it was not stopped', $frame->agentType,
            )));
        }
    }

    /**
     * @param CommandRequestDTO $request Peer request with the original return address
     * @param PeerAgentRestartDTO $frame Validated peer frame
     * @throws AgentDaemonCreationFailedException When a placement profile cannot be built
     * @throws HilosException When the project cannot describe a placement candidate
     * @throws LogicException When an agent declares a negative resource cost or the reserved slot
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    private function fromPeerChecked(CommandRequestDTO $request, PeerAgentRestartDTO $frame): void
    {
        $registry = Hilos::appClass()::AGENTS[$frame->agentType] ?? null;
        $localNodeId = Hilos::$cluster?->localNodeId() ?? HilosClusterNode::STANDALONE_NODE_ID;
        $id = $frame->agentIndex === null
            ? $frame->agentType
            : $frame->agentType . AgentConstants::ID_SEPARATOR . $frame->agentIndex;
        if ($registry === null || (AgentRegistry::requiresIndex($registry) !== ($frame->agentIndex !== null))) {
            $this->reply($request, CommandReplyDTO::error($frame->correlationId, sprintf('No agent %s in this installation', $id)));
            return;
        }
        $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        if ($frame->inPlace) {
            if (AgentRegistry::placement($registry) !== AgentPlacement::POLICY || $frame->nodeId !== $localNodeId) {
                $this->reply($request, CommandReplyDTO::error($frame->correlationId, 'Invalid in-place restart request'));
                return;
            }
            $this->executeHere($request, $frame->agentType, $frame->agentIndex, $id, $localNodeId, false, $now);
            return;
        }
        if (AgentRegistry::scope($registry) === AgentScope::NODE) {
            if ($frame->nodeId !== $localNodeId) {
                $this->reply($request, CommandReplyDTO::error($frame->correlationId, 'Restart request reached the wrong node'));
                return;
            }
            $this->executeHere($request, $frame->agentType, $frame->agentIndex, $id, $localNodeId, false, $now);
            return;
        }
        $this->executeAsLeader($request, $frame->agentType, $frame->agentIndex, $id, $localNodeId, $now);
    }

    /**
     * @param string $nodeId Node id to look up
     * @return bool Whether the node is known
     */
    private function nodeKnown(string $nodeId): bool
    {
        foreach (Hilos::$cluster?->registry()->snapshot() ?? [] as $node) {
            if ($node->nodeId === $nodeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $nodeId Node id to look up
     * @return bool Whether the node is online
     */
    private function nodeOnline(string $nodeId): bool
    {
        return Hilos::$cluster?->registry()->isOnline($nodeId) === true;
    }

    /**
     * Sends one restart request over the existing peer channel.
     *
     * @param CommandRequestDTO $request Original held command
     * @param string $type Agent type
     * @param ?string $index Agent index
     * @param string $target Receiving node
     * @param bool $inPlace Whether the leader sanctioned an in-place restart
     * @param bool $toLeader Whether this is the initial route to the cluster leader
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     */
    private function forward(
        CommandRequestDTO $request,
        string $type,
        ?string $index,
        string $target,
        bool $inPlace,
        bool $toLeader,
    ): void
    {
        $frame = new PeerAgentRestartDTO(
            $request->correlationId,
            $request->originNodeId ?? Hilos::$cluster->localNodeId(),
            $type,
            $index,
            $inPlace ? $target : ($request->payload[CommandConstants::FIELD_NODE_ID] ?? null),
            $inPlace,
        );
        $sent = $this->sendRestartFrame !== null
            ? ($this->sendRestartFrame)($target, $frame)
            : ($this->peerServer !== null && ($this->peerServer)()?->sendToNode($target, $frame) === true);
        if (!$sent) {
            $message = $toLeader ? sprintf('The leader %s is unreachable', $target)
                : sprintf('Node %s is silent', NodeName::label($target, Hilos::$rt?->hilosClusterNodes[$target]?->name));
            $this->reply($request, CommandReplyDTO::error($request->correlationId, $message));
        }
    }

    /**
     * Restarts a leader singleton here or coordinates a POLICY agent's current host.
     *
     * @param CommandRequestDTO $request Held operator request
     * @param string $type Agent type
     * @param ?string $index Agent index
     * @param string $id Agent id
     * @param string $nodeId Local node id
     * @param float $now Monotonic seconds
     * @throws ClusterConfigurationException When cluster membership cannot be read
     * @throws ClusterDisabledException When a cluster-only lookup has no cluster
     * @throws EnvException When cluster settings cannot be read
     * @throws AgentDaemonCreationFailedException When a placement profile cannot be built
     * @throws HilosException When the project cannot describe a placement candidate
     * @throws LogicException When an agent declares a negative resource cost or the reserved slot
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function executeAsLeader(
        CommandRequestDTO $request,
        string $type,
        ?string $index,
        string $id,
        string $nodeId,
        float $now,
    ): void {
        $cluster = Hilos::$cluster;
        if ($cluster === null || !$cluster->amLeader()) {
            $leaderId = $cluster?->leadership()->leaderId();
            $message = $leaderId === null
                ? 'The cluster has no leader right now'
                : ($cluster->registry()->isOnline($leaderId)
                    ? sprintf('Node %s does not lead the cluster', $nodeId)
                    : sprintf('The leader %s is unreachable', $leaderId));
            $this->reply($request, CommandReplyDTO::error($request->correlationId, $message));
            return;
        }
        $registry = Hilos::appClass()::AGENTS[$type];
        $askedNodeId = $request->payload[CommandConstants::FIELD_NODE_ID] ?? null;
        if (AgentRegistry::placement($registry) !== AgentPlacement::POLICY) {
            if ($askedNodeId !== null && $askedNodeId !== $nodeId) {
                $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                    'Agent %s is not running on node %s', $id, $askedNodeId,
                )));
                return;
            }
            $this->executeHere($request, $type, $index, $id, $nodeId, true, $now);
            return;
        }
        $placement = $cluster->placement();
        if (isset($this->runs[$id])) {
            $this->addWaiter($this->runs[$id], $request, $now);
            return;
        }
        $record = $placement?->registry()->get($id);
        if ($record?->state === PlacementState::Placing) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf('Agent %s is still being placed', $id)));
            return;
        }
        if ($record === null || $record->state !== PlacementState::Started || $record->nodeId === null) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf('Agent %s is not running anywhere', $id)));
            return;
        }
        if ($record->nodeId !== $nodeId && !$this->nodeOnline($record->nodeId)) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Node %s is silent', NodeName::label($record->nodeId, Hilos::$rt?->hilosClusterNodes[$record->nodeId]?->name),
            )));
            return;
        }
        if ($askedNodeId !== null && $askedNodeId !== $record->nodeId) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Agent %s runs on node %s, not on %s', $id, $record->nodeId, $askedNodeId,
            )));
            return;
        }
        if (($freeze = Hilos::$rt?->hilosProtectedModeRuntime) !== null
            && $freeze->phase !== ProtectedModeRuntime::PHASE_INACTIVE) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Node %s is frozen by protected mode; %s was not stopped', $record->nodeId, $id,
            )));
            return;
        }
        $target = $placement->bestNodeFor($type, $index);
        if ($target === null) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'No node can take %s now; it was not stopped', $id,
            )));
            return;
        }
        if ($target === $record->nodeId) {
            if ($target === $nodeId) {
                $this->executeHere($request, $type, $index, $id, $nodeId, true, $now);
            } else {
                $this->forward($request, $type, $index, $target, true, false);
            }
            return;
        }
        $run = $this->runs[$id] = new AgentRestartRun($id, $type, $index, $record->nodeId, true, true);
        $this->revision++;
        $this->addWaiter($run, $request, $now);
        try {
            $placement->restartElsewhere($type, $index, $record->nodeId, $target);
        } catch (EventLoopException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            $this->finish($id, CommandReplyDTO::error('', sprintf(
                'Agent %s could not be stopped: %s', $id, $failure->getMessage(),
            )), $run);
        }
    }

    /**
     * Completes a POLICY restart when the placement coordinator sees its new host.
     *
     * @param string $type Agent type
     * @param ?string $index Agent index
     * @param string $nodeId New host
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function onRestartPlaced(string $type, ?string $index, string $nodeId): void
    {
        $id = $index === null ? $type : $type . AgentConstants::ID_SEPARATOR . $index;
        $this->finish($id, CommandReplyDTO::ok('', [
            CommandConstants::FIELD_AGENT_ID => $id,
            CommandConstants::FIELD_NODE_ID => $nodeId,
            CommandConstants::FIELD_NODE_NAME => Hilos::$rt?->hilosClusterNodes[$nodeId]?->name,
        ]));
    }

    /**
     * Completes a POLICY restart that found no working placement.
     *
     * @param string $type Agent type
     * @param ?string $index Agent index
     * @param string $reason Refusal reason
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function onRestartNotPlaced(string $type, ?string $index, string $reason): void
    {
        $id = $index === null ? $type : $type . AgentConstants::ID_SEPARATOR . $index;
        $this->finish($id, CommandReplyDTO::error('', sprintf('Agent %s did not start again: %s', $id, $reason)));
    }

    /**
     * Opens the mark before asking the old worker to stop and the new one to start.
     *
     * @param CommandRequestDTO $request Held operator request
     * @param string $type Agent type
     * @param ?string $index Agent index
     * @param string $id Agent id
     * @param string $nodeId Node hosting the old instance
     * @param bool $leaderDependent Whether loss of leadership cancels this restart
     * @param float $now Monotonic seconds
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function executeHere(
        CommandRequestDTO $request,
        string $type,
        ?string $index,
        string $id,
        string $nodeId,
        bool $leaderDependent,
        float $now,
    ): void {
        $worker = ($this->workerServer)();
        if ($worker === null) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, 'This daemon restarts no agents'));
            return;
        }
        if (
            ($freeze = Hilos::$rt?->hilosProtectedModeRuntime) !== null
            && $freeze->phase !== ProtectedModeRuntime::PHASE_INACTIVE
        ) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Node %s is frozen by protected mode; %s was not stopped', $nodeId, $id,
            )));
            return;
        }
        if (isset($this->runs[$id])) {
            $this->addWaiter($this->runs[$id], $request, $now);
            return;
        }
        if (!$this->agents->hasAgent($id)) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Agent %s is not running on node %s', $id, $nodeId,
            )));
            return;
        }
        if (!$this->agents->isAgentStarted($id)) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Agent %s is not running on node %s', $id, $nodeId,
            )));
            return;
        }
        if ($worker->placedWorkerId($type, $index) === null) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Agent %s could not be stopped: its worker link is gone', $id,
            )));
            return;
        }

        $registry = Hilos::appClass()::AGENTS[$type];
        $placedByLeader = AgentRegistry::placement($registry) === AgentPlacement::POLICY;
        $inPlacePlacement = $placedByLeader && Hilos::$cluster?->placement() !== null;
        $run = $this->runs[$id] = new AgentRestartRun($id, $type, $index, $nodeId, $leaderDependent, false, $inPlacePlacement);
        $this->revision++;
        $this->addWaiter($run, $request, $now);
        try {
            if ($inPlacePlacement) {
                Hilos::$cluster->placement()->beginInPlaceRestart($type, $index);
            }
            $worker->restartAgentHere($type, $index, $placedByLeader);
            if (
                !$this->agents->hasAgent($id)
                && !$worker->isAgentAwaitingWorker($id)
                && !$worker->isAgentAwaitingStopReport($id)
            ) {
                $this->finish($id, CommandReplyDTO::error('', sprintf(
                    'Agent %s did not start again: the local start gate refused it', $id,
                )), $run);
            }
        } catch (LogicException) {
            $this->finish($id, CommandReplyDTO::error($request->correlationId, sprintf(
                'Agent %s could not be stopped: its worker link is gone', $id,
            )), $run);
        } catch (EventLoopException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            $message = $this->agents->isAgentStarted($id)
                ? sprintf('Agent %s could not be stopped: its worker link is gone', $id)
                : sprintf('Agent %s did not start again: %s', $id, $failure->getMessage());
            $this->finish($id, CommandReplyDTO::error($request->correlationId, $message), $run);
        }
    }

    /**
     * @param string $agentId Agent reported started by its worker
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function onAgentStarted(string $agentId): void
    {
        $run = $this->runs[$agentId] ?? null;
        if ($run === null || $run->placementMove) {
            return;
        }
        $this->finish($agentId, CommandReplyDTO::ok('', [
            CommandConstants::FIELD_AGENT_ID => $agentId,
            CommandConstants::FIELD_NODE_ID => $run->nodeId,
            CommandConstants::FIELD_NODE_NAME => Hilos::$rt?->hilosClusterNodes[$run->nodeId]?->name,
        ]));
    }

    /**
     * @param string $agentId Agent whose replacement failed
     * @param string $reason Worker-reported reason
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function onAgentStartFailed(string $agentId, string $reason): void
    {
        if (isset($this->runs[$agentId]) && !$this->runs[$agentId]->placementMove) {
            $this->finish($agentId, CommandReplyDTO::error('', sprintf(
                'Agent %s did not start again: %s', $agentId, $reason,
            )));
        }
    }

    /**
     * @param list<string> $agentIds Agents lost with a worker
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function onAgentsLost(array $agentIds): void
    {
        foreach ($agentIds as $agentId) {
            ($this->captureAgentLoss($agentId))();
        }
    }

    /**
     * Binds a received worker loss to the restart it actually interrupted, including absence.
     * The returned effect cannot answer a later restart of the same agent.
     *
     * @param string $agentId Agent lost with the worker
     * @return Closure(): void Once-only refusal under the caller's existing lifecycle guard
     */
    public function captureAgentLoss(string $agentId): Closure
    {
        $run = $this->runs[$agentId] ?? null;
        return function () use ($agentId, $run): void {
            if ($run === null || ($this->runs[$agentId] ?? null) !== $run) {
                return;
            }
            if ($run?->placementMove) {
                Hilos::$cluster?->placement()?->noteAgentStartFailed($run->type, $run->index, 'its worker was lost');
                if (isset($this->runs[$agentId])) {
                    $this->finish($agentId, CommandReplyDTO::error('', sprintf(
                        'Agent %s did not start again: its worker was lost', $agentId,
                    )), $run);
                }
                return;
            }
            $this->finish($agentId, CommandReplyDTO::error('', sprintf(
                'Agent %s did not start again: its worker was lost', $agentId,
            )), $run);
        };
    }

    /**
     * @param string $correlationId Caller no longer waiting
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function onCommandAbandoned(string $correlationId): void
    {
        $removed = false;
        foreach ($this->runs as $run) {
            if (isset($run->waiters[$correlationId])) {
                unset($run->waiters[$correlationId]);
                $removed = true;
            }
        }
        if ($removed) {
            $this->rearmDeadlineTimer();
        }
    }

    /**
     * @param string $nodeId Node that left the mesh
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function onNodeLeft(string $nodeId): void
    {
        foreach ($this->runs as $id => $run) {
            $removed = false;
            foreach ($run->waiters as $correlationId => $waiter) {
                if ($waiter->request->originNodeId === $nodeId) {
                    unset($run->waiters[$correlationId]);
                    $removed = true;
                }
            }
            if ($removed) {
                $this->rearmDeadlineTimer();
            }
            $placementNodeId = Hilos::$cluster?->placement()?->registry()->get($id)?->nodeId;
            if ($run->nodeId === $nodeId && (!$run->placementMove || $placementNodeId === $nodeId)) {
                $this->finish($id, CommandReplyDTO::error('', sprintf(
                    'Node %s went silent during the restart of %s',
                    NodeName::label($nodeId, Hilos::$rt?->hilosClusterNodes[$nodeId]?->name), $id,
                )), $run);
            }
        }
    }

    /**
     * Closes leader-dependent restarts after this master loses its term.
     *
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    public function onLostLeadership(): void
    {
        foreach ($this->runs as $id => $run) {
            if ($run->leaderDependent) {
                $this->finish($id, CommandReplyDTO::error('', sprintf(
                    'Leadership changed during the restart of %s', $id,
                )), $run);
            }
        }
    }

    /**
     * @param ?float $now Monotonic seconds, injectable for deadline observations
     * @throws EventLoopException When the next live caller deadline cannot be armed or canceled
     */
    public function tick(?float $now = null): void
    {
        $now ??= hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        if ($now < $this->nextWaitDeadline) {
            $this->deadlineTimer?->armAt(is_finite($this->nextWaitDeadline) ? $this->nextWaitDeadline : null);
            return;
        }
        $expired = [];
        foreach ($this->runs as $run) {
            foreach ($run->waiters as $correlationId => $waiter) {
                if ($now < $waiter->askedAt + CommandChannelWindows::AGENT_WAIT_SECONDS) {
                    continue;
                }
                unset($run->waiters[$correlationId]);
                $expired[] = [$run->id, $waiter];
            }
        }
        $this->rearmDeadlineTimer();
        foreach ($expired as [$id, $waiter]) {
            $this->reply($waiter->request, CommandReplyDTO::error($waiter->request->correlationId, sprintf(
                'Agent %s is not up yet after %ds; the restart goes on',
                $id,
                (int)CommandChannelWindows::AGENT_WAIT_SECONDS,
            )));
        }
    }

    /**
     * @param AgentRestartRun $run Existing restart receiving one caller
     * @param CommandRequestDTO $request Caller and return address
     * @param float $now Monotonic seconds when the caller joined
     * @throws EventLoopException When the nearest live caller deadline cannot be armed
     */
    private function addWaiter(AgentRestartRun $run, CommandRequestDTO $request, float $now): void
    {
        if (isset($run->waiters[$request->correlationId])) {
            return;
        }
        $run->waiters[$request->correlationId] = new AgentRestartWaiter($request, $now);
        $this->rearmDeadlineTimer();
    }

    /** @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled */
    private function rearmDeadlineTimer(): void
    {
        $this->nextWaitDeadline = INF;
        foreach ($this->runs as $run) {
            foreach ($run->waiters as $waiter) {
                $this->nextWaitDeadline = min($this->nextWaitDeadline, $waiter->askedAt + CommandChannelWindows::AGENT_WAIT_SECONDS);
            }
        }
        $this->deadlineTimer?->armAt(is_finite($this->nextWaitDeadline) ? $this->nextWaitDeadline : null);
    }

    /**
     * @param string $id Open restart id
     * @param CommandReplyDTO $outcome Result copied to all still waiting callers
     * @param ?AgentRestartRun $expectedRun Original run for a completion or cohort that may have reentered
     * @throws EventLoopException When the nearest live caller deadline cannot be armed or canceled
     */
    private function finish(string $id, CommandReplyDTO $outcome, ?AgentRestartRun $expectedRun = null): void
    {
        $run = $this->runs[$id] ?? null;
        if ($run === null || ($expectedRun !== null && $run !== $expectedRun)) {
            return;
        }
        if ($run->inPlacePlacement) {
            $reason = $outcome->payload[CommandConstants::FIELD_MESSAGE] ?? 'restart failed';
            Hilos::$cluster?->placement()?->endInPlaceRestart(
                $run->type,
                $run->index,
                $outcome->isOk() || $this->agents->isAgentStarted($id),
                is_string($reason) ? $reason : 'restart failed',
            );
        }
        unset($this->runs[$id]);
        $this->revision++;
        $this->rearmDeadlineTimer();
        foreach ($run->waiters as $waiter) {
            $reply = new CommandReplyDTO($waiter->request->correlationId, $outcome->status, $outcome->payload);
            $this->reply($waiter->request, $reply);
        }
    }

    /**
     * @param CommandRequestDTO $request Request carrying the return path
     * @param CommandReplyDTO $reply Outcome for this request
     */
    private function reply(CommandRequestDTO $request, CommandReplyDTO $reply): void
    {
        try {
            if ($request->correlationId === '') {
                return;
            }
            $originNodeId = $request->originNodeId;
            if ($originNodeId !== null && $originNodeId !== (Hilos::$cluster?->localNodeId() ?? HilosClusterNode::STANDALONE_NODE_ID)) {
                $this->replyOrigins->note($request->correlationId, $originNodeId, microtime(true));
            }
            Hilos::$sr->queueSignal(
                new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::COMMAND_REPLY),
                new SignalName($request->correlationId),
                $reply,
            );
        } catch (Throwable $failure) {
            Logger::error('Agent restart reply could not be queued: ' . $failure->getMessage());
        }
    }
}

/** One marked restart and its remaining command waiters. */
final class AgentRestartRun
{
    /** @var array<string, AgentRestartWaiter> */
    public array $waiters = [];

    /**
     * @param string $id Agent id
     * @param string $type Agent type
     * @param ?string $index Agent index
     * @param string $nodeId Original hosting node
     * @param bool $leaderDependent Whether this master must still lead for the run
     * @param bool $placementMove Whether placement status supplies the final outcome
     * @param bool $inPlacePlacement Whether POLICY placement is retained over the local stop
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $index,
        public readonly string $nodeId,
        public readonly bool $leaderDependent,
        public readonly bool $placementMove = false,
        public readonly bool $inPlacePlacement = false,
    ) {
    }
}

/** One caller's bounded wait for an open restart. */
final readonly class AgentRestartWaiter
{
    /**
     * @param CommandRequestDTO $request Command and return path
     * @param float $askedAt Monotonic seconds when the caller joined this restart
     */
    public function __construct(public CommandRequestDTO $request, public float $askedAt)
    {
    }
}
