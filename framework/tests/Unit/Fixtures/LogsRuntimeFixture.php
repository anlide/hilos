<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fixtures;

use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The live registry behind log names, independent of the reported log picture. */
trait LogsRuntimeFixture
{
    private ?RtContext $previousLogsRt = null;

    /** Mounts a fresh registry without inventing nodes for mirror entries. */
    private function mountLogsRuntime(): void
    {
        $this->previousLogsRt = Hilos::$rt;
        Hilos::$rt = new class extends RtContext {
            /** Keeps the fixture free of project collections. */
            public function configure(): void
            {
            }
        };
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
    }

    /** Releases the fixture's writer and restores the previous runtime. */
    private function restoreLogsRuntime(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        Hilos::$rt = $this->previousLogsRt;
    }

    /**
     * @param string $nodeId Stable node address
     * @param string $name Current or last known registry name
     * @param bool $online Whether this node is still connected
     */
    private function publishLogNode(string $nodeId, string $name, bool $online = true): void
    {
        Hilos::$rt->hilosClusterNodes->actions->publish($nodeId, $name, 'master', [], null, $online, 1_800_000_000.0);
    }
}
