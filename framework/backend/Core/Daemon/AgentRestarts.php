<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
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
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\DaemonSection\DaemonRestartingAgent;
use Hilos\Hilos;
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

    /** Next waiter deadline; an idle main-loop pass reads one scalar. */
    private float $nextWaitDeadline = INF;

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
     * @param ?float $now Current time, injectable for the wait-window test
     */
    public function request(CommandRequestDTO $request, ?float $now = null): void
    {
        try {
            $this->requestChecked($request, $now ?? microtime(true));
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
     * @param float $now Current time
     * @throws ClusterConfigurationException When the cluster node configuration cannot be read
     * @throws ClusterDisabledException When a cluster-only lookup has no cluster
     * @throws EnvException When a cluster setting cannot be read
     * @throws AgentDaemonCreationFailedException When a placement profile cannot be built
     * @throws HilosException When the project cannot describe a placement candidate
     * @throws LogicException When an agent declares a negative resource cost
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
                $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf('Node %s is silent', $askedNodeId)));
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
     * @throws LogicException When an agent declares a negative resource cost
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
        if ($frame->inPlace) {
            if (AgentRegistry::placement($registry) !== AgentPlacement::POLICY || $frame->nodeId !== $localNodeId) {
                $this->reply($request, CommandReplyDTO::error($frame->correlationId, 'Invalid in-place restart request'));
                return;
            }
            $this->executeHere($request, $frame->agentType, $frame->agentIndex, $id, $localNodeId, false, microtime(true));
            return;
        }
        if (AgentRegistry::scope($registry) === AgentScope::NODE) {
            if ($frame->nodeId !== $localNodeId) {
                $this->reply($request, CommandReplyDTO::error($frame->correlationId, 'Restart request reached the wrong node'));
                return;
            }
            $this->executeHere($request, $frame->agentType, $frame->agentIndex, $id, $localNodeId, false, microtime(true));
            return;
        }
        $this->executeAsLeader($request, $frame->agentType, $frame->agentIndex, $id, $localNodeId, microtime(true));
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
            $message = $toLeader ? sprintf('The leader %s is unreachable', $target) : sprintf('Node %s is silent', $target);
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
     * @param float $now Current time
     * @throws ClusterConfigurationException When cluster membership cannot be read
     * @throws ClusterDisabledException When a cluster-only lookup has no cluster
     * @throws EnvException When cluster settings cannot be read
     * @throws AgentDaemonCreationFailedException When a placement profile cannot be built
     * @throws HilosException When the project cannot describe a placement candidate
     * @throws LogicException When an agent declares a negative resource cost
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
            $this->runs[$id]->waiters[$request->correlationId] = new AgentRestartWaiter($request, $now);
            $this->nextWaitDeadline = min($this->nextWaitDeadline, $now + CommandChannelWindows::AGENT_WAIT_SECONDS);
            return;
        }
        $record = $placement?->registry()->get($id);
        if ($record?->state === PlacementState::Placing) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf('Agent %s is still being placed', $id)));
            return;
        }
        if ($record === null || $record->state !== PlacementState::Started) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf('Agent %s is not running anywhere', $id)));
            return;
        }
        if ($record->nodeId !== $nodeId && !$this->nodeOnline($record->nodeId)) {
            $this->reply($request, CommandReplyDTO::error($request->correlationId, sprintf(
                'Node %s is silent', $record->nodeId,
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
        $this->runs[$id] = new AgentRestartRun($id, $type, $index, $record->nodeId, true, true);
        $this->runs[$id]->waiters[$request->correlationId] = new AgentRestartWaiter($request, $now);
        $this->nextWaitDeadline = min($this->nextWaitDeadline, $now + CommandChannelWindows::AGENT_WAIT_SECONDS);
        $this->revision++;
        try {
            $placement->restartElsewhere($type, $index, $record->nodeId, $target);
        } catch (Throwable $failure) {
            $this->finish($id, CommandReplyDTO::error('', sprintf(
                'Agent %s could not be stopped: %s', $id, $failure->getMessage(),
            )));
        }
    }

    /**
     * Completes a POLICY restart when the placement coordinator sees its new host.
     *
     * @param string $type Agent type
     * @param ?string $index Agent index
     * @param string $nodeId New host
     */
    public function onRestartPlaced(string $type, ?string $index, string $nodeId): void
    {
        $id = $index === null ? $type : $type . AgentConstants::ID_SEPARATOR . $index;
        $this->finish($id, CommandReplyDTO::ok('', [
            CommandConstants::FIELD_AGENT_ID => $id,
            CommandConstants::FIELD_NODE_ID => $nodeId,
        ]));
    }

    /**
     * Completes a POLICY restart that found no working placement.
     *
     * @param string $type Agent type
     * @param ?string $index Agent index
     * @param string $reason Refusal reason
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
     * @param float $now Current time
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
            $this->runs[$id]->waiters[$request->correlationId] = new AgentRestartWaiter($request, $now);
            $this->nextWaitDeadline = min($this->nextWaitDeadline, $now + CommandChannelWindows::AGENT_WAIT_SECONDS);
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
        $this->runs[$id] = new AgentRestartRun($id, $type, $index, $nodeId, $leaderDependent, false, $inPlacePlacement);
        $this->runs[$id]->waiters[$request->correlationId] = new AgentRestartWaiter($request, $now);
        $this->nextWaitDeadline = min($this->nextWaitDeadline, $now + CommandChannelWindows::AGENT_WAIT_SECONDS);
        $this->revision++;
        if ($inPlacePlacement) {
            Hilos::$cluster->placement()->beginInPlaceRestart($type, $index);
        }
        try {
            $worker->restartAgentHere($type, $index, $placedByLeader);
            if (
                !$this->agents->hasAgent($id)
                && !$worker->isAgentAwaitingWorker($id)
                && !$worker->isAgentAwaitingStopReport($id)
            ) {
                $this->finish($id, CommandReplyDTO::error('', sprintf(
                    'Agent %s did not start again: the local start gate refused it', $id,
                )));
            }
        } catch (LogicException) {
            $this->finish($id, CommandReplyDTO::error($request->correlationId, sprintf(
                'Agent %s could not be stopped: its worker link is gone', $id,
            )));
        } catch (Throwable $failure) {
            $message = $this->agents->isAgentStarted($id)
                ? sprintf('Agent %s could not be stopped: its worker link is gone', $id)
                : sprintf('Agent %s did not start again: %s', $id, $failure->getMessage());
            $this->finish($id, CommandReplyDTO::error($request->correlationId, $message));
        }
    }

    /** @param string $agentId Agent reported started by its worker */
    public function onAgentStarted(string $agentId): void
    {
        $run = $this->runs[$agentId] ?? null;
        if ($run === null || $run->placementMove) {
            return;
        }
        $this->finish($agentId, CommandReplyDTO::ok('', [
            CommandConstants::FIELD_AGENT_ID => $agentId,
            CommandConstants::FIELD_NODE_ID => $run->nodeId,
        ]));
    }

    /**
     * @param string $agentId Agent whose replacement failed
     * @param string $reason Worker-reported reason
     */
    public function onAgentStartFailed(string $agentId, string $reason): void
    {
        if (isset($this->runs[$agentId]) && !$this->runs[$agentId]->placementMove) {
            $this->finish($agentId, CommandReplyDTO::error('', sprintf(
                'Agent %s did not start again: %s', $agentId, $reason,
            )));
        }
    }

    /** @param list<string> $agentIds Agents lost with a worker */
    public function onAgentsLost(array $agentIds): void
    {
        foreach ($agentIds as $agentId) {
            $run = $this->runs[$agentId] ?? null;
            if ($run?->placementMove) {
                Hilos::$cluster?->placement()?->noteAgentStartFailed($run->type, $run->index, 'its worker was lost');
                if (isset($this->runs[$agentId])) {
                    $this->finish($agentId, CommandReplyDTO::error('', sprintf(
                        'Agent %s did not start again: its worker was lost', $agentId,
                    )));
                }
                continue;
            }
            $this->onAgentStartFailed($agentId, 'its worker was lost');
        }
    }

    /** @param string $correlationId Caller no longer waiting */
    public function onCommandAbandoned(string $correlationId): void
    {
        foreach ($this->runs as $run) {
            unset($run->waiters[$correlationId]);
        }
    }

    /** @param string $nodeId Node that left the mesh */
    public function onNodeLeft(string $nodeId): void
    {
        foreach ($this->runs as $id => $run) {
            foreach ($run->waiters as $correlationId => $waiter) {
                if ($waiter->request->originNodeId === $nodeId) {
                    unset($run->waiters[$correlationId]);
                }
            }
            $placementNodeId = Hilos::$cluster?->placement()?->registry()->get($id)?->nodeId;
            if ($run->nodeId === $nodeId && (!$run->placementMove || $placementNodeId === $nodeId)) {
                $this->finish($id, CommandReplyDTO::error('', sprintf(
                    'Node %s went silent during the restart of %s', $nodeId, $id,
                )));
            }
        }
    }

    /** Closes leader-dependent restarts after this master loses its term. */
    public function onLostLeadership(): void
    {
        foreach ($this->runs as $id => $run) {
            if ($run->leaderDependent) {
                $this->finish($id, CommandReplyDTO::error('', sprintf(
                    'Leadership changed during the restart of %s', $id,
                )));
            }
        }
    }

    /** @param float $now Current time in seconds */
    public function tick(float $now): void
    {
        if ($now < $this->nextWaitDeadline) {
            return;
        }
        $this->nextWaitDeadline = INF;
        foreach ($this->runs as $run) {
            foreach ($run->waiters as $correlationId => $waiter) {
                if ($now - $waiter->askedAt < CommandChannelWindows::AGENT_WAIT_SECONDS) {
                    $this->nextWaitDeadline = min(
                        $this->nextWaitDeadline,
                        $waiter->askedAt + CommandChannelWindows::AGENT_WAIT_SECONDS,
                    );
                    continue;
                }
                unset($run->waiters[$correlationId]);
                $this->reply($waiter->request, CommandReplyDTO::error($correlationId, sprintf(
                    'Agent %s is not up yet after %ds; the restart goes on',
                    $run->id,
                    (int)CommandChannelWindows::AGENT_WAIT_SECONDS,
                )));
            }
        }
    }

    /**
     * @param string $id Open restart id
     * @param CommandReplyDTO $outcome Result copied to all still waiting callers
     */
    private function finish(string $id, CommandReplyDTO $outcome): void
    {
        $run = $this->runs[$id] ?? null;
        if ($run === null) {
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
     * @param float $askedAt Time the caller joined this restart
     */
    public function __construct(public CommandRequestDTO $request, public float $askedAt)
    {
    }
}
