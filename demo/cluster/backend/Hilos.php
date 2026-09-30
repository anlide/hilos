<?php

declare(strict_types=1);

namespace Demo\Cluster;

use Demo\Cluster\Database\ClusterDbContext;
use Demo\Cluster\Environment\ClusterEnvCatalog;
use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Cluster\Probe\ClaimerProbeAgent;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Cluster\Probe\FleetProbeAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\TruthSource\SharedOwnersKey;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseGuarantee;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Hilos - Main app facade for the cluster demo.
 *
 * A deliberately minimal, headless project: no pages, no WebSocket, no browser
 * context — just the framework's cluster probes the multi-node cluster harness (HIL-185)
 * drives, all five of them: the placeable no-op fleet it observes, the claimer it stages a
 * two-owner split with, the ballast that holds capacity, the per-node probe that writes and
 * reads a row of the one database the stand shares (HIL-712), and the per-node probe that owns
 * its node's set of the probe notes (HIL-1116). The probes and their runtime collections are the
 * framework's (HIL-1211); this facade only lists them. Its own runtime context holds nothing but
 * the framework-owned protected mode singleton, mounted per node so the daemon truth source has
 * a local writer seam.
 * The whole CLUSTER_* configuration is inherited from the framework env catalog, so
 * the facade only names the env catalog, the agent registry, the database context,
 * and this minimal runtime context.
 *
 * @property-read ClusterDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read ClusterRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = ClusterEnvCatalog::class;

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    public const array PAGES = [];

    public const array AGENTS = [
        HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
        HilosAgentType::HILOS_PROBE_CLAIMER => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_CLAIMER],
        HilosAgentType::HILOS_PROBE_BALLAST => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_BALLAST],
        HilosAgentType::HILOS_PROBE_DB => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_DB],
        HilosAgentType::HILOS_PROBE_RT_SET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_RT_SET],
    ];

    /**
     * The one runtime collection this demo lets two owners hold - on purpose.
     *
     * A receipt, not a permission, and the only row here whose debt is not a leaf: the claimer
     * holds the whole status collection while every fleet member holds its own row, and that is the
     * split the cluster scenarios exist to exercise. Startup would refuse the pair without this
     * row, exactly as it refuses one that nobody wrote down.
     */
    public const array SHARED_RT_OWNERS = [
        HilosProbeFleetStatus::RT_COLLECTION => [
            SharedOwnersKey::OWNERS => [ClaimerProbeAgent::class, FleetProbeAgent::class],
            SharedOwnersKey::DEBT => 'intentional: this demo exists to exercise the runtime two-owner guard',
        ],
    ];

    /**
     * Creates the cluster demo database context.
     *
     * @return ClusterDbContext Cluster demo database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new ClusterDbContext();
    }

    /**
     * Creates the cluster demo runtime context.
     *
     * @return ?ClusterRtContext Cluster demo runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new ClusterRtContext();
    }
}
