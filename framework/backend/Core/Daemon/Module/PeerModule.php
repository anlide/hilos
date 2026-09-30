<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Module;

use Closure;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerMarkers;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Tls\ClusterTlsConfig;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseMarker;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * Cluster peer transport module: binds the peer port that forms the mesh, runs
 * consensus, and executes placement.
 *
 * Active only when this daemon opts into cluster mode, so a non-cluster single-node
 * daemon stays first-class and never binds the peer port.
 */
final class PeerModule implements DaemonModule
{
    /** @var Closure(): PeerMarkers Reads this node's markers once, before the peer port opens */
    private readonly Closure $localMarkers;

    /**
     * @param ?Closure(): PeerMarkers $localMarkers Reads this node's markers; the database marker when null -
     *     a unit test that builds the server without a database hands its own
     */
    public function __construct(?Closure $localMarkers = null)
    {
        $this->localMarkers = $localMarkers ?? self::databaseMarkers(...);
    }

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
     * The node's TLS files are checked first ({@see ClusterTlsConfig::fromEnv()}), then the node
     * reads the markers it names to its peers on every handshake - today the database marker
     * ({@see DatabaseMarker}), written here by the first node to start. That read is the one
     * database access of the master outside its loop that the peer channel needs: a one-time
     * bootstrap read before {@see DaemonManager::run()}, which the rule against heavy work in the
     * master allows (docs/agents/antipatterns/heavy-work-in-master.md, *Exceptions*), as it allows
     * the anonymization gate's schema read and the admin view mode latch. The handshake itself
     * touches no database: the markers live in memory from here on.
     *
     * @param DaemonManager $daemon Daemon to register the peer server on
     * @param DaemonContext $context Resolved path context (unused; peer wiring is env-driven)
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config or its TLS files are missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     * @throws DatabaseException When the database marker cannot be read or written
     * @throws InvalidArgumentException When the markers read name a kind without the place it was read from
     */
    public function register(DaemonManager $daemon, DaemonContext $context): void
    {
        // Checked before the peer port opens: a node whose files cannot carry the channel does
        // not start, and says why.
        $tls = ClusterTlsConfig::fromEnv(Hilos::$cluster->identity());

        $peerServer = new PeerServer(
            Hilos::$env[EnvConstants::CLUSTER_PEER_HOST]->string(),
            Hilos::$env[EnvConstants::CLUSTER_PEER_PORT]->int(),
            Hilos::$cluster->identity(),
            PeerAddress::parseList(Hilos::$env[EnvConstants::CLUSTER_SEEDS]->string()),
            $tls,
            ($this->localMarkers)(),
            Hilos::$cluster->connectionPolicy(),
        );
        $daemon->registerServer($peerServer);
    }

    /**
     * Reads the database marker, writing it first on a database that has none, and says so.
     *
     * @return PeerMarkers This node's markers
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     * @throws DatabaseException When the database marker cannot be read or written
     * @throws InvalidArgumentException When a kind comes without its place; the database kind is built with both
     */
    private static function databaseMarkers(): PeerMarkers
    {
        $marker = DatabaseMarker::ensure(Hilos::$cluster->identity()->nodeId);
        $place = DatabaseMarker::place();
        Logger::info("Database marker {$marker->marker} written by {$marker->writtenBy} at {$marker->writtenAt}, read from {$place}");

        return new PeerMarkers([PeerMarkers::DATABASE => $marker->marker], [PeerMarkers::DATABASE => $place]);
    }
}
