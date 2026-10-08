<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\DaemonCronRuleReport;
use Hilos\DaemonSection\DTO\DaemonAgentCronSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterCronSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosClusterNode;

/** Per-node owner of the Daemon picture, reporting it whole and without an acknowledgement. */
final class DaemonNodeAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON_NODE;

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER => DaemonMasterProcessRosterSignalData::class,
        HilosSignalConstants::DAEMON_MASTER_CRON => DaemonMasterCronSignalData::class,
        HilosSignalConstants::DAEMON_AGENT_CRON => DaemonAgentCronSignalData::class,
    ];

    private const float CHANGE_INTERVAL_SECONDS = 5.0;
    private const float REANNOUNCE_INTERVAL_SECONDS = 60.0;

    private NodeDaemonPicture $picture;
    private bool $dirty = false;
    private float $lastReportAt = 0.0;
    private ?DaemonMasterCronSignalData $masterCron = null;

    /** @var array<string, list<DaemonCronRuleReport>> Cron rows reported by started agents */
    private array $agentCron = [];

    /**
     * Starts with the local cluster identity and announces the first complete picture immediately.
     *
     * @throws InvalidArgumentException When the report cannot be routed
     * @throws HilosException When the local cluster identity cannot be resolved
     */
    public function onStart(): void
    {
        $cluster = Hilos::$cluster;
        $nodeId = $cluster?->localNodeId() ?? HilosClusterNode::STANDALONE_NODE_ID;
        $role = $cluster?->isEnabled() === true ? $cluster->identity()->role : NodeRole::Master;
        $this->picture = new NodeDaemonPicture($nodeId, $role, time());
        $this->report(microtime(true));
    }

    /**
     * Accepts a complete replacement from a future node-picture producer.
     *
     * @param NodeDaemonPicture $picture The local node's new complete content
     * @throws InvalidArgumentException When the replacement names another node
     */
    public function updatePicture(NodeDaemonPicture $picture): void
    {
        if ($picture->nodeId !== $this->picture->nodeId) {
            throw new InvalidArgumentException('A node agent cannot report another node');
        }
        if (!$this->picture->sameContent($picture)) {
            $this->picture = $picture;
            $this->dirty = true;
        }
    }

    /**
     * @param AgentSignalData $data Parsed master frame
     * @param string $sender Full sender address
     * @param string $name Signal name
     * @throws InvalidAgentSignalPayloadException When the declared payload was not hydrated
     * @throws AgentException When the frame is not from this node's master
     * @throws AgentUnknownSignalException When the signal is not this agent's
     * @throws InvalidArgumentException When the replacement names another node
     * @throws InvalidFormatException When a cron section cannot be built
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::DAEMON_MASTER_CRON:
                $cron = $data->data;
                if (!$cron instanceof DaemonMasterCronSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, DaemonMasterCronSignalData::class, $cron);
                }
                if ($sender !== SignalSource::DAEMON || $cron->nodeId !== $this->picture->nodeId) {
                    throw new AgentException('Daemon cron frame must come from this node\'s master');
                }
                $this->masterCron = $cron;
                $this->rebuildCron(time());
                return;

            case HilosSignalConstants::DAEMON_AGENT_CRON:
                $report = $data->data;
                if (!$report instanceof DaemonAgentCronSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, DaemonAgentCronSignalData::class, $report);
                }
                $id = AgentId::fromId($report->agentId);
                if ($sender !== SignalSource::describe(new SignalSource(SignalSource::AGENT, $id->type, $id->index))) {
                    throw new AgentException('Daemon agent cron report must come from the named agent');
                }
                if ($report->rules === []) {
                    unset($this->agentCron[$report->agentId]);
                } else {
                    $this->agentCron[$report->agentId] = $report->rules;
                }
                $this->rebuildCron(time());
                return;

            case HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER:
                $roster = $data->data;
                if (!$roster instanceof DaemonMasterProcessRosterSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, DaemonMasterProcessRosterSignalData::class, $roster);
                }
                if ($sender !== SignalSource::DAEMON || $roster->nodeId !== $this->picture->nodeId) {
                    throw new AgentException('Daemon process roster must come from this node\'s master');
                }
                $liveAgents = [];
                foreach ($roster->roster->workers as $worker) {
                    foreach ($worker->agents as $agent) {
                        $liveAgents[$agent->id] = true;
                    }
                }
                $this->agentCron = array_intersect_key($this->agentCron, $liveAgents);
                $this->updatePicture($this->picture->withProcesses($roster->roster));
                $this->rebuildCron(time());
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Sends a changed picture after the change throttle, or an unchanged whole picture each minute.
     *
     * @param float $now Wall clock of this tick
     * @throws InvalidArgumentException When the report cannot be routed
     */
    public function reportIfDue(float $now): void
    {
        $elapsed = $now - $this->lastReportAt;
        if ($elapsed >= self::REANNOUNCE_INTERVAL_SECONDS || ($this->dirty && $elapsed >= self::CHANGE_INTERVAL_SECONDS)) {
            $this->report($now);
        }
    }

    /**
     * @throws InvalidArgumentException When the report cannot be routed
     * @throws InvalidFormatException When a cron section cannot be rebuilt
     */
    public function onTick(): void
    {
        $now = time();
        foreach ($this->picture->cron?->rules ?? [] as $rule) {
            if ($rule->nextRunAt !== null && $rule->nextRunAt <= $now) {
                $this->rebuildCron($now);
                break;
            }
        }
        $this->reportIfDue(microtime(true));
    }

    /**
     * Rebuilds the cron section from the last whole reports kept by this node agent.
     *
     * @param int $now Unix time after which the next run is sought
     * @throws InvalidArgumentException When the replacement names another node
     * @throws InvalidFormatException When a cron row is invalid
     */
    private function rebuildCron(int $now): void
    {
        if ($this->masterCron === null) {
            $this->updatePicture($this->picture->withCron(null));
            return;
        }

        $rows = [];
        foreach ($this->masterCron->rules as $rule) {
            $rows[] = new DaemonCronRulePicture(
                null,
                $rule->name,
                $rule->expression,
                $rule->lastRunAt,
                $this->masterCron->idleReason === null ? CronRule::nextRunAfter($rule->expression, $now) : null,
            );
        }
        $agentCron = $this->agentCron;
        ksort($agentCron, SORT_STRING);
        foreach ($agentCron as $agentId => $rules) {
            foreach ($rules as $rule) {
                $rows[] = new DaemonCronRulePicture(
                    $agentId,
                    $rule->name,
                    $rule->expression,
                    $rule->lastRunAt,
                    CronRule::nextRunAfter($rule->expression, $now),
                );
            }
        }
        $this->updatePicture($this->picture->withCron(new DaemonCronPicture($this->masterCron->idleReason, $rows)));
    }

    /**
     * @param float $now Wall clock of this report
     * @throws InvalidArgumentException When the report cannot be routed
     */
    private function report(float $now): void
    {
        $this->picture = $this->picture->sampledAt((int)$now);
        $this->sendToAgent(HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT, new DaemonNodePictureSignalData($this->picture));
        $this->dirty = false;
        $this->lastReportAt = $now;
    }
}
