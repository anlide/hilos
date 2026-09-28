<?php

declare(strict_types=1);

namespace Hilos\AdminViewMode;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\NonProductionGate;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Logger;
use JsonException;

/**
 * AdminViewModeStartup - the admin view mode decided once, at the start of a node (HIL-1249).
 *
 * Reads what {@see AdminViewModeStartupVerdict} decides from - the variable, whether the node is a
 * stand, and on production both halves of the latch - and carries the decision out: writes the
 * halves it owes, and says in the journal why the mode is what it is.
 *
 * Called from {@see DaemonManager::boot()}, after composition and before {@see DaemonManager::run()}
 * binds a server or starts a worker: a one-time bootstrap read, which is what the master process is
 * allowed before its loop. The answer goes into the node's runtime row; each worker is handed that
 * row by the master when it comes up, and follows it from then on through the RT sync.
 *
 * It never refuses the start. A latch that cannot be read or written keeps the mode off with an
 * ERROR line: the mode may not be opened on the strength of a half-read latch, and taking the
 * site down over a mode that stays closed anyway would cost more than it protects.
 */
final class AdminViewModeStartup
{
    /**
     * Decides the admin view mode of this node, writing the latch where production owes it.
     *
     * @return bool Whether the mode is on for this node until it restarts
     */
    public static function run(): bool
    {
        try {
            $variableOn = Hilos::$env[EnvConstants::HILOS_ADMIN_VIEW_MODE_ENABLED]->bool();
        } catch (EnvException $failure) {
            Logger::error(
                'Admin view mode: HILOS_ADMIN_VIEW_MODE_ENABLED could not be read (' . $failure->getMessage()
                . '), so the mode stays off.',
            );

            return false;
        }

        if (NonProductionGate::admitted()) {
            $decision = AdminViewModeStartupVerdict::decide(true, $variableOn, false, false);
            if ($decision->enabled) {
                Logger::info('Admin view mode: on.');
            }

            return $decision->enabled;
        }

        try {
            return self::decideInProduction($variableOn);
        } catch (HilosException | JsonException $failure) {
            Logger::error(
                'Admin view mode: the latch could not be read or written (' . $failure->getMessage()
                . '), so the mode stays off.',
            );

            return false;
        }
    }

    /**
     * Reads both halves of the latch, writes the ones the decision owes and journals the outcome.
     *
     * A missing half is written back from the one that survived, so the record keeps saying who
     * closed the mode and when; only the first closing writes this node and this moment.
     *
     * @param bool $variableOn Whether HILOS_ADMIN_VIEW_MODE_ENABLED asks for the mode
     * @return bool Whether the mode is on for this node until it restarts
     * @throws HilosException When the environment, the log directory or the database cannot be read or written
     * @throws JsonException When the latch file cannot be encoded
     */
    private static function decideInProduction(bool $variableOn): bool
    {
        $logRoot = dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]->string());
        $environment = Hilos::$env[EnvConstants::APP_ENV]->string();
        $node = Hilos::$env[EnvConstants::CLUSTER_NODE_ID]->string();
        $file = AdminViewModeLatchFile::pathIn($logRoot);
        $table = AdminViewModeLatchTable::TABLE;

        $fileLatch = AdminViewModeLatchFile::read($logRoot, $environment, $node);
        $rowLatch = AdminViewModeLatchTable::read();
        $decision = AdminViewModeStartupVerdict::decide(false, $variableOn, $fileLatch !== null, $rowLatch !== null);
        $record = $fileLatch ?? $rowLatch ?? [
            AdminViewModeLatchFile::KEY_ENVIRONMENT => $environment,
            AdminViewModeLatchFile::KEY_NODE => $node,
            AdminViewModeLatchFile::KEY_CLOSED_AT => time(),
        ];

        if ($decision->writeFile) {
            AdminViewModeLatchFile::publish(
                $logRoot,
                $record[AdminViewModeLatchFile::KEY_ENVIRONMENT],
                $record[AdminViewModeLatchFile::KEY_NODE],
                $record[AdminViewModeLatchFile::KEY_CLOSED_AT],
            );
        }
        if ($decision->writeRow) {
            AdminViewModeLatchTable::close(
                $record[AdminViewModeLatchFile::KEY_ENVIRONMENT],
                $record[AdminViewModeLatchFile::KEY_NODE],
                $record[AdminViewModeLatchFile::KEY_CLOSED_AT],
            );
        }

        if ($decision->closedForGood) {
            Logger::warning(
                'Admin view mode: this production node started with the mode off, so the mode is closed on this '
                . "installation for good ({$file} and the row in {$table}).",
            );
        } elseif ($decision->writeFile) {
            Logger::warning(
                "Admin view mode: the latch file {$file} was missing and has been written back from the row in {$table}.",
            );
        } elseif ($decision->writeRow) {
            Logger::warning("Admin view mode: the row in {$table} was missing and has been written back from {$file}.");
        }

        if ($decision->conflict) {
            Logger::error(
                'Admin view mode: HILOS_ADMIN_VIEW_MODE_ENABLED is on, but this production installation was once started '
                . "with it off, so the mode stays off. To allow it, stop every node, delete {$file} on each node and run "
                . "DELETE FROM {$table}, then start again.",
            );
        }
        if ($decision->onInProduction) {
            Logger::warning(
                'Admin view mode: on in production - a non-admin may open the admin section and look, and change nothing.',
            );
        }

        return $decision->enabled;
    }
}
