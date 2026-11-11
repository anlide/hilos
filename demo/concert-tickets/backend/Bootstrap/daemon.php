<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\ConcertTickets\Core\Daemon\ConcertTicketsDaemonManager;
use Demo\ConcertTickets\Database\Database;
use Demo\ConcertTickets\Hilos;
use Hilos\Core\Daemon\DaemonApplication;

/**
 * Daemon - Entry point for the concert-tickets demo daemon.
 *
 * Designed to run in Docker container under docker.php management. The invariant
 * startup spine lives in DaemonApplication; ConcertTicketsDaemonManager declares this daemon's
 * servers, routes, and modules.
 */

DaemonApplication::run(
    bootstrapDir: __DIR__,
    projectRoot: dirname(__DIR__, 2),
    hilosClass: Hilos::class,
    daemonClass: ConcertTicketsDaemonManager::class,
    persistenceInit: static function (): void {
        Database::initialize();
    },
);
