<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Module;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Cluster peer transport module: binds the peer port that forms the mesh, runs
 * consensus, and executes placement.
 *
 * Active only when this daemon opts into cluster mode, so a non-cluster single-node
 * daemon stays first-class and never binds the peer port.
 */
final class PeerModule implements DaemonModule
{
    /**
     * @return bool True when cluster mode is enabled
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function isActive(): bool
    {
        return Hilos::$cluster?->isEnabled() ?? false;
    }

    /**
     * Builds the peer transport server from the CLUSTER_* env and registers it.
     *
     * @param DaemonManager $daemon Daemon to register the peer server on
     * @param DaemonContext $context Resolved path context (unused; peer wiring is env-driven)
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     */
    public function register(DaemonManager $daemon, DaemonContext $context): void
    {
        $peerServer = new PeerServer(
            Hilos::$env[EnvConstants::CLUSTER_PEER_HOST],
            Hilos::$env->int(EnvConstants::CLUSTER_PEER_PORT),
            Hilos::$cluster->identity(),
            PeerAddress::parseList(Hilos::$env[EnvConstants::CLUSTER_SEEDS]),
        );
        $daemon->registerServer($peerServer);
    }
}
