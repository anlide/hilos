<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Cluster\Core\Daemon\ClusterDaemonManager;
use Demo\Cluster\Database\Database;
use Demo\Cluster\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for a cluster demo node daemon.
 *
 * Headless (no WebSocket, no frontend): each node runs the HTTP status server, the
 * worker server, the command server the harness inspects over, and — when cluster
 * mode is enabled — the peer transport that forms the mesh, runs consensus (on a
 * master), and executes placement. Role, identity, master set, and timeouts all
 * come from CLUSTER_* env, so the same daemon is a master or a data-plane node
 * purely by configuration. The startup spine lives in DaemonApplication;
 * ClusterDaemonManager declares this node's servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: ClusterDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);
