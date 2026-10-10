<?php

declare(strict_types=1);

namespace Hilos\Cluster\Probe;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\NonProductionGate;
use Hilos\Hilos;

/**
 * ClusterProbe - the framework's cluster probe agents, and the one threshold they start behind.
 *
 * The probes are test instruments of a cluster stand. {@see FleetProbeAgent} is a fleet of
 * synthetic workers the leader keeps placed across the data plane; {@see ClaimerProbeAgent} is a
 * deliberate second owner of the collection that fleet writes; {@see BallastProbeAgent} holds a
 * slice of its node's declared ram; {@see DbProbeAgent} and {@see RtSetProbeAgent} are per-node
 * replicas that write the shared database and this node's set of a runtime collection. A project
 * lists the ones its cluster scenarios need in its own AGENTS, one row each, with the record
 * taken from here:
 *
 *     HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
 *
 * Why the record is the framework's and not written by each project: its flags ARE the
 * behavior. The claimer and the ballast are indexed for one reason beyond carrying an index -
 * the framework's policy-placement sweep skips indexed agents and the fleet supervisor
 * ({@see ProbeFleetSupervisor}) places only the fleet, so nothing brings either up until a
 * scenario addresses one. A claimer written without INDEXED would be placed by the sweep in every
 * run, and the two-owner split it exists to stage would stop being the scenario's own choice.
 *
 * Why a probe starts only where {@see self::mayRunHere()} says so - on a clustered node of a
 * non-production environment: the fleet deliberately occupies its worker, since the blocked
 * worker is the load being simulated, and it stays within one onTick budget per worker turn
 * ({@see FleetProbeAgent}); the set probe off a cluster has no node and so no set to
 * own; and a production-like node refuses the `test:` commands that drive the rest anyway. So
 * the same project on one node, on its own Playwright stand and in production carries the rows
 * and starts none of them: the worker server's start passes over a probe there without a record
 * and without a failure card.
 */
final class ClusterProbe
{
    /**
     * Capability tag of a data-plane node able to host the probe fleet and the claimer.
     *
     * A capability tag is the hard placement gate: the leader refuses to place an agent on a node
     * whose advertised capabilities (CLUSTER_NODE_CAPABILITIES) do not include every tag the agent
     * requires. A node that carries placed work advertises it - the slaves of binance-btc-tracker
     * and ecommerce-shop, the masters of online-testing - so the fleet is placed only where a
     * stand means its work to run.
     */
    public const string CAPABILITY_WORKER = 'worker';

    /**
     * Resource a node of a cluster stand declares a stock of, and the one the ballast costs.
     *
     * A key=value token in CLUSTER_NODE_CAPABILITIES is a stock of the named resource, and the
     * leader subtracts the cost of every agent it places on the node from it. A stand's slaves
     * declare different amounts, so a scenario can show work landing in proportion to what each
     * node declares; a stand whose masters take no placed work leaves their capabilities empty.
     */
    public const string RESOURCE_RAM = 'ram';

    /** @var int Members of the probe fleet the leader keeps placed, under indexes 0 to FLEET_SIZE - 1 */
    public const int FLEET_SIZE = 10;

    /** @var array<string, array<string, mixed>> Registry record of each probe, keyed by its agent type */
    public const array AGENTS = [
        HilosAgentType::HILOS_PROBE_FLEET => [
            AgentRegistryKey::WORKER => FleetProbeAgent::class,
            AgentRegistryKey::DAEMON => FleetProbeAgentDaemon::class,
            // The leader places a fleet of these, so every instance carries its own index.
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        HilosAgentType::HILOS_PROBE_CLAIMER => [
            AgentRegistryKey::WORKER => ClaimerProbeAgent::class,
            AgentRegistryKey::DAEMON => ClaimerProbeAgentDaemon::class,
            // Indexed for the same reason the fleet is, and for one more: the framework's
            // policy-placement sweep skips indexed agents, and the fleet supervisor places only
            // the fleet. So nothing brings a claimer up until a scenario addresses one, which is
            // what keeps the deliberate split out of every other run.
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        HilosAgentType::HILOS_PROBE_BALLAST => [
            AgentRegistryKey::WORKER => BallastProbeAgent::class,
            AgentRegistryKey::DAEMON => BallastProbeAgentDaemon::class,
            // Indexed like the claimer and for the same reason: the framework's policy-placement
            // sweep skips indexed agents and the fleet supervisor places only the fleet, so a
            // ballast holds capacity only while the scenario that asked for it runs.
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        HilosAgentType::HILOS_PROBE_DB => [
            AgentRegistryKey::WORKER => DbProbeAgent::class,
            AgentRegistryKey::DAEMON => DbProbeAgentDaemon::class,
            // The only axis it declares, and the one its scenario is built on: a replica on
            // every node, so "node A writes and node B reads" names two particular nodes
            // rather than wherever a placement happened to land. Neither INDEXED nor
            // PLACEMENT may stand beside it - a node replica has no index and no node to
            // pick - and topology validation refuses both.
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        HilosAgentType::HILOS_PROBE_RT_SET => [
            AgentRegistryKey::WORKER => RtSetProbeAgent::class,
            AgentRegistryKey::DAEMON => RtSetProbeAgentDaemon::class,
            // A replica on every node for the reason the database probe is one: each owns the
            // set of the probe notes named by its own node, so "s1 writes its set and s2 may
            // not" names two particular nodes (HIL-1116).
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
    ];

    /**
     * Whether a cluster probe may start on this node: it is in a cluster, and its environment is
     * a known, non-production one.
     *
     * Fail-closed on both halves, as {@see NonProductionGate::admitted()} is on its own: a
     * cluster flag that cannot be read is not evidence of a cluster, and the cost is lopsided the
     * same way - a probe missing from a stand fails a scenario that names it, a probe started in
     * production blocks a real worker.
     *
     * @return bool True when the node is clustered and its APP_ENV is not production-like
     */
    public static function mayRunHere(): bool
    {
        $cluster = Hilos::$cluster;
        if ($cluster === null) {
            return false;
        }

        try {
            if (!$cluster->isEnabled()) {
                return false;
            }
        } catch (EnvException) {
            return false;
        }

        return NonProductionGate::admitted();
    }

    /**
     * Whether the agent class is the worker class of one of the probes.
     *
     * Asked by class and not by the registry key, because the key is the project's to write and
     * the class is what the record carries: a row listing a probe under another key is still the
     * probe, and still starts behind {@see self::mayRunHere()}.
     *
     * @param ?string $workerClass Worker class of a registry row, null when the row names none
     * @return bool True when the class is the worker class of a probe
     */
    public static function isProbe(?string $workerClass): bool
    {
        if ($workerClass === null) {
            return false;
        }

        foreach (self::AGENTS as $registryEntry) {
            if (AgentRegistry::workerClass($registryEntry) === $workerClass) {
                return true;
            }
        }

        return false;
    }
}
