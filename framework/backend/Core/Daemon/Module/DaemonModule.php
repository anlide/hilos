<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Module;

use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;

/**
 * An opt-in daemon subsystem: a self-contained unit that a daemon lists in
 * {@see DaemonManager::modules()} and that registers its own server(s) and reads its
 * own env when active.
 *
 * A module turns what used to be a conditional inline block in daemon.php into a
 * first-class object, so adding a subsystem later is a new module in the list, not
 * another edit to the bootstrap.
 */
interface DaemonModule
{
    /**
     * @return bool True when this daemon should enable the module (its activation predicate)
     */
    public function isActive(): bool;

    /**
     * Builds and registers the module's server(s) and performs its one-time bootstrap
     * side-effects. Called during composition only when {@see isActive()} is true.
     *
     * @param DaemonManager $daemon Daemon to register server(s) on
     * @param DaemonContext $context Resolved path context
     */
    public function register(DaemonManager $daemon, DaemonContext $context): void;
}
