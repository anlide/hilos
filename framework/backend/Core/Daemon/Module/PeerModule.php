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
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
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
     * @param ?Closure(): PeerMarkers $localMarkers Reads this node's markers; the database marker, the marker of
     *     every cluster directory and the admin view mode variable when null - a unit test without a database hands its own
     */
    public function __construct(?Closure $localMarkers = null)
    {
        $this->localMarkers = $localMarkers ?? self::localMarkers(...);
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
     * reads the markers it names to its peers on every handshake - the database marker
     * ({@see DatabaseMarker}), the marker of every cluster directory of `$fs`
     * ({@see ClusterDirectoryMarker}), each written here by the first node to start, and the admin
     * view mode variable. Those reads
     * are the one database and file access of the master outside its loop that the peer channel
     * needs: a one-time bootstrap read before {@see DaemonManager::run()}, which the rule against
     * heavy work in the master allows (docs/agents/antipatterns/heavy-work-in-master.md,
     * *Exceptions*), as it allows the anonymization gate's schema read and the admin view mode
     * latch. The handshake itself touches neither the database nor the disk: the markers live in
     * memory from here on.
     *
     * @param DaemonManager $daemon Daemon to register the peer server on
     * @param DaemonContext $context Resolved path context (unused; peer wiring is env-driven)
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config or its TLS files are missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     * @throws DatabaseException When the database marker cannot be read or written
     * @throws DirectoryCreateException When a cluster directory is absent and cannot be created
     * @throws FileReadException When a cluster directory's marker cannot be read, or is a file this build does not write
     * @throws FileWriteException When a cluster directory's marker is absent and cannot be written
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
     * Reads the database marker, then the marker of every cluster directory, writing each first where
     * there is none yet, then the admin view mode variable, and says so.
     *
     * The database comes first: a refusal names the first breach, so a node on another database is
     * named by its database and not by a directory. No `$fs` context, or no cluster directory in it,
     * leaves the database marker alone.
     *
     * @return PeerMarkers This node's markers
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     * @throws DatabaseException When the database marker cannot be read or written
     * @throws DirectoryCreateException When a cluster directory is absent and cannot be created
     * @throws FileReadException When a cluster directory's marker cannot be read, or is a file this build does not write
     * @throws FileWriteException When a cluster directory's marker is absent and cannot be written
     * @throws InvalidArgumentException When a kind comes without its place; every kind is built with both
     */
    private static function localMarkers(): PeerMarkers
    {
        $nodeId = Hilos::$cluster->identity()->nodeId;
        $database = DatabaseMarker::ensure($nodeId);
        $place = DatabaseMarker::place();
        Logger::info("Database marker {$database->marker} written by {$database->writtenBy} at {$database->writtenAt}, read from {$place}");
        $values = [PeerMarkers::DATABASE => $database->marker];
        $places = [PeerMarkers::DATABASE => $place];

        foreach (Hilos::$fs?->clusterDirectories() ?? [] as $name => $path) {
            $directory = ClusterDirectoryMarker::ensure($name, $path, $nodeId);
            Logger::info(
                "Cluster directory {$name} marker {$directory->marker} written by {$directory->writtenBy}"
                . " at {$directory->writtenAt}, read from " . ClusterDirectoryMarker::pathIn($path),
            );
            $values[PeerMarkers::directoryKind($name)] = $directory->marker;
            $places[PeerMarkers::directoryKind($name)] = ClusterDirectoryMarker::place($name, $path);
        }

        try {
            $adminViewModeOn = Hilos::$env[EnvConstants::HILOS_ADMIN_VIEW_MODE_ENABLED]->bool();
        } catch (EnvException) {
            $adminViewModeOn = false;
        }
        $values[PeerMarkers::ADMIN_VIEW_MODE] = PeerMarkers::adminViewMode($adminViewModeOn);
        $places[PeerMarkers::ADMIN_VIEW_MODE] = 'the variable ' . EnvConstants::HILOS_ADMIN_VIEW_MODE_ENABLED->name;
        Logger::info(
            "Admin view mode marker {$values[PeerMarkers::ADMIN_VIEW_MODE]}, read from"
            . ' ' . $places[PeerMarkers::ADMIN_VIEW_MODE],
        );

        return new PeerMarkers($values, $places);
    }
}
