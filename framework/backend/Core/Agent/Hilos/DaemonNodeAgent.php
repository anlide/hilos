<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosClusterNode;

/** Per-node owner of the Daemon picture, reporting it whole and without an acknowledgement. */
final class DaemonNodeAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_DAEMON_NODE;

    private const float CHANGE_INTERVAL_SECONDS = 5.0;
    private const float REANNOUNCE_INTERVAL_SECONDS = 60.0;

    private NodeDaemonPicture $picture;
    private bool $dirty = false;
    private float $lastReportAt = 0.0;

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

    /** @throws InvalidArgumentException When the report cannot be routed */
    public function onTick(): void
    {
        $this->reportIfDue(microtime(true));
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
