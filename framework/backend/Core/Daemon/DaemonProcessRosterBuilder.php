<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Generator;
use Hilos\Cluster\Placement\PlacementRegistry;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use SplMinHeap;

/** Builds one whole master frame over bounded main-loop passes. */
final class DaemonProcessRosterBuilder
{
    private const int STAGE_AGENTS = 1;
    private const int STAGE_PLACEMENTS = 2;
    private const int STAGE_SORT_AGENTS = 3;
    private const int STAGE_SORT_UNPLACED = 4;
    private const int STAGE_WORKERS = 5;
    private const int STAGE_DONE = 6;

    private int $stage = self::STAGE_DONE;
    private ?AgentManagerDaemon $agentManager = null;
    private ?Generator $agents = null;
    private ?Generator $placements = null;

    /** @var list<DaemonWorkerPicture> */
    private array $workers = [];

    /** @var array<int, DaemonWorkerPicture> */
    private array $workersByIndex = [];

    /** @var array<int, ?int> Worker index to birth tick sampled while proc_get_status still reported it running */
    private array $workerStartTicks = [];

    /** @var array<string, DaemonAgentPicture> */
    private array $agentPictures = [];

    /** @var array<string, int> */
    private array $agentWorkerIndexes = [];

    /** @var array<int, list<DaemonAgentPicture>> */
    private array $sortedWorkerAgents = [];

    /** @var list<string> */
    private array $sortedUnplaced = [];

    /** @var list<DaemonWorkerPicture> */
    private array $builtWorkers = [];

    private SplMinHeap $agentIds;
    private SplMinHeap $unplacedIds;
    private int $workerOffset = 0;
    private ?DaemonProcessRoster $result = null;
    private bool $leader = false;
    private int $workerRestarts24h = 0;

    /** @param ProcessMetricsReader $metrics Reader initialized before the runtime loop */
    public function __construct(private readonly ProcessMetricsReader $metrics)
    {
        $this->agentIds = new SplMinHeap();
        $this->unplacedIds = new SplMinHeap();
    }

    /**
     * Starts a new whole-frame build; a revision change abandons the old one.
     *
     * @param WorkerServer $workerServer Local worker source
     * @param AgentManagerDaemon $agentManager Started-agent source
     * @param ?PlacementRegistry $placementRegistry Leader's placement source, if any
     * @param bool $leader Whether this node may state which agents run nowhere
     * @throws InvalidFormatException When tracked worker identity fields are invalid
     */
    public function start(
        WorkerServer $workerServer,
        AgentManagerDaemon $agentManager,
        ?PlacementRegistry $placementRegistry,
        bool $leader,
    ): void {
        $this->workers = $workerServer->liveWorkerPictures();
        $this->workersByIndex = [];
        $this->workerStartTicks = [];
        foreach ($this->workers as $worker) {
            $this->workersByIndex[$worker->index] = $worker;
            $this->workerStartTicks[$worker->index] = $worker->pid === null
                ? null
                : $this->metrics->readStartTimeTicks($worker->pid);
        }
        $this->agentManager = $agentManager;
        $this->agents = (static function () use ($agentManager): Generator {
            foreach ($agentManager->getAgents() as $id => $_) {
                yield $id;
            }
        })();
        $this->placements = (static function () use ($placementRegistry): Generator {
            foreach ($placementRegistry?->all() ?? [] as $record) {
                yield $record;
            }
        })();
        $this->agentPictures = [];
        $this->agentWorkerIndexes = [];
        $this->sortedWorkerAgents = [];
        $this->sortedUnplaced = [];
        $this->builtWorkers = [];
        $this->agentIds = new SplMinHeap();
        $this->unplacedIds = new SplMinHeap();
        $this->workerOffset = 0;
        $this->result = null;
        $this->leader = $leader;
        $this->workerRestarts24h = $workerServer->workerRestarts24h();
        $this->stage = self::STAGE_AGENTS;
    }

    /**
     * @param int $budget Maximum source rows or sorted values to process on this pass
     * @throws InvalidFormatException When a source row cannot form a consistent roster
     */
    public function advance(int $budget): void
    {
        while ($budget > 0 && $this->stage !== self::STAGE_DONE) {
            switch ($this->stage) {
                case self::STAGE_AGENTS:
                    if (!$this->agents?->valid()) {
                        $this->stage = self::STAGE_PLACEMENTS;
                        break;
                    }
                    $this->addAgent($this->agents->current());
                    $this->agents->next();
                    $budget--;
                    break;

                case self::STAGE_PLACEMENTS:
                    if (!$this->leader || !$this->placements?->valid()) {
                        $this->stage = self::STAGE_SORT_AGENTS;
                        break;
                    }
                    $record = $this->placements->current();
                    if ($record->state->runsNowhere()) {
                        $this->unplacedIds->insert($record->agentId());
                    }
                    $this->placements->next();
                    $budget--;
                    break;

                case self::STAGE_SORT_AGENTS:
                    if ($this->agentIds->isEmpty()) {
                        $this->stage = self::STAGE_SORT_UNPLACED;
                        break;
                    }
                    $id = $this->agentIds->extract();
                    $this->sortedWorkerAgents[$this->agentWorkerIndexes[$id]][] = $this->agentPictures[$id];
                    $budget--;
                    break;

                case self::STAGE_SORT_UNPLACED:
                    if ($this->unplacedIds->isEmpty()) {
                        $this->stage = self::STAGE_WORKERS;
                        break;
                    }
                    $this->sortedUnplaced[] = $this->unplacedIds->extract();
                    $budget--;
                    break;

                case self::STAGE_WORKERS:
                    if (!isset($this->workers[$this->workerOffset])) {
                        $this->result = new DaemonProcessRoster(
                            $this->builtWorkers,
                            $this->leader ? $this->sortedUnplaced : null,
                            $this->workerRestarts24h,
                        );
                        $this->stage = self::STAGE_DONE;
                        break;
                    }
                    $worker = $this->workers[$this->workerOffset++];
                    $this->builtWorkers[] = new DaemonWorkerPicture(
                        $worker->index,
                        $worker->kind,
                        $worker->pid,
                        $worker->pid === null || $this->workerStartTicks[$worker->index] === null
                            ? null
                            : $this->metrics->readResidentBytes($worker->pid, $this->workerStartTicks[$worker->index]),
                        $this->sortedWorkerAgents[$worker->index] ?? [],
                    );
                    $budget--;
                    break;
            }
        }
    }

    /** @return ?DaemonProcessRoster Complete result, or null while a build is in progress */
    public function result(): ?DaemonProcessRoster
    {
        return $this->result;
    }

    /** @param string $id Agent id from the manager's active roster */
    private function addAgent(string $id): void
    {
        if (!$this->agentManager?->isAgentStarted($id)) {
            return;
        }
        $workerInfo = $this->agentManager->getAgentWorkerInfo($id);
        $worker = $workerInfo === null ? null : ($this->workersByIndex[$workerInfo->workerIndex] ?? null);
        if ($worker === null || ($worker->kind === WorkerConstants::TYPE_MONOPOLISTIC) !== $workerInfo->isMonopolistic) {
            return;
        }
        $type = AgentId::fromId($id)->type;
        $registry = Hilos::appClass()::AGENTS[$type] ?? null;
        if ($registry === null) {
            return;
        }
        $scope = AgentRegistry::scope($registry);
        $placement = $scope === AgentScope::NODE
            ? DaemonAgentPicture::PLACEMENT_NODE
            : match (AgentRegistry::placement($registry)) {
                AgentPlacement::LEADER => DaemonAgentPicture::PLACEMENT_LEADER,
                AgentPlacement::POLICY => DaemonAgentPicture::PLACEMENT_POLICY,
            };
        $this->agentPictures[$id] = new DaemonAgentPicture(
            $id,
            $scope === AgentScope::NODE ? DaemonAgentPicture::SCOPE_NODE : DaemonAgentPicture::SCOPE_CLUSTER,
            $placement,
        );
        $this->agentWorkerIndexes[$id] = $worker->index;
        $this->agentIds->insert($id);
    }
}
