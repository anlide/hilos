<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandExecutionSite;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Database\Migration;
use Hilos\Database\Seed;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The CLI process spine: the invariant startup sequence every cli.php shares, lifted out
 * of the four near-identical bootstraps into one framework entrypoint.
 *
 * A cli.php collapses to a single {@see run()} call naming its Hilos facade, its CLI
 * manager class, and its database connect. The spine runs the env prelude, configures the
 * migration/seed paths, constructs the manager, connects the database when the command
 * asks for one, and returns its exit code — all under one try/catch that logs and exits
 * ERROR.
 *
 * One gate shapes the database connect, and the command owns it: the manager is built
 * before any connection, then answers whether the named command needs one. A command
 * marked {@see DatabaseFreeCommand} runs with the database untouched — that is what lets
 * db:wait poll a MySQL that is still down, db:test:reset create a database that does not
 * exist yet, and the cluster demo inspect a network-partitioned node that cannot reach
 * MySQL either. An unregistered name skips the connect too, so a typo answers "unknown
 * command" instead of a connection failure.
 *
 * A second gate sits in front of it and is read the same way — off the command's own
 * declaration ({@see CommandExecution}). A command that writes state the daemon owns is
 * admissible only while no daemon is running, so the spine asks the machine before it lets
 * one start ({@see refuseOfflineWriteBesideALiveDaemon()}).
 */
final class CliApplication
{
    /** Why a presence check could not be made: the only way it fails is an environment that names no channel. */
    private const string UNCHECKABLE_DETAIL = 'HILOS_DAEMON_HOST or COMMAND_PORT is missing or invalid';

    /**
     * Runs the CLI from its thin entrypoint. Terminates the process; never returns.
     *
     * @param string $bootstrapDir Directory containing the Database/Migration tree
     * @param string $projectRoot Project root that holds .env
     * @param class-string<Hilos> $hilosClass Project Hilos facade whose catalogs drive env/cluster init
     * @param class-string<CliManager> $cliManagerClass CLI manager to construct and run
     * @param list<string> $argv Command-line arguments; $argv[1] is the command name
     * @param callable(): void $databaseInit Database connect, run only for a command that needs one
     * @return never
     */
    public static function run(
        string $bootstrapDir,
        string $projectRoot,
        string $hilosClass,
        string $cliManagerClass,
        array $argv,
        callable $databaseInit,
    ): void {
        // $argv[1] is the command; null means none was named, which the manager reads as help.
        $command = $argv[1] ?? null;

        try {
            EntrypointPrelude::initEnvironment($hilosClass, $projectRoot);

            // The schema track is named by the prelude, which every process runs; the routines
            // and seeds are configured here, by the entrypoint that applies them.
            Migration::setRoutinesPath($bootstrapDir . '/../Database/Migration/Routines');
            Seed::setSeedPath($bootstrapDir . '/../Database/Migration/Seed');

            $cliManager = new $cliManagerClass($argv);

            $refusal = self::refuseOfflineWriteBesideALiveDaemon($cliManager->execution($command), $command);
            if ($refusal !== null) {
                exit($refusal);
            }

            if ($cliManager->requiresDatabase($command)) {
                $databaseInit();
            }

            exit($cliManager->run());
        } catch (Throwable $e) {
            Logger::error('CLI failed: ' . $e->getMessage(), [
                ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
                ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
                ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
            ]);
            exit(ExitCode::ERROR);
        }
    }

    /**
     * Refuses a command that writes state the daemon owns while a daemon is answering.
     *
     * The project rule is that the daemon does the work; the commands that legitimately break it
     * do so because they run BEFORE the daemon exists - migrations, seeds, a database reset, a
     * fixture. Run one beside a live daemon and there are two writers to state that has one
     * owner, which surfaces later as data nobody can explain rather than as an error anyone can
     * read.
     *
     * The gate is here rather than in the commands for the same reason the database connect is:
     * a command declares WHAT it is, and the spine acts on the declaration. A gate written into
     * each command would be forgotten by the next command written.
     *
     * @param ?CommandExecution $execution What the named command declares about itself; null when it is not registered
     * @param ?string $command Command name from argv, or null when none was named
     * @return ?int Exit code to die with, or null when the command may run
     */
    private static function refuseOfflineWriteBesideALiveDaemon(?CommandExecution $execution, ?string $command): ?int
    {
        // No command named resolves to help, which reads and never writes; the null is ruled out
        // here so the refusal below always has a name to put in front of the operator.
        if ($command === null || $execution?->site !== CommandExecutionSite::CLI_OFFLINE_WRITE) {
            return null;
        }

        return self::offlineWriteVerdict($command, DaemonPresenceProbe::probe(), DaemonPresenceProbe::address());
    }

    /**
     * Words the refusal for one presence, or lets the command through.
     *
     * Split from the probe so the decision can be exercised for every presence without a daemon
     * to run or refuse to run: the probe answers a question about the machine, this answers what
     * to do about the answer.
     *
     * @param string $command Command name, named in the refusal
     * @param DaemonPresence $presence What the probe found
     * @param ?string $address host:port the probe asked, or null when the environment names none
     * @return ?int Exit code to die with, or null when the command may run
     */
    public static function offlineWriteVerdict(string $command, DaemonPresence $presence, ?string $address): ?int
    {
        if ($presence === DaemonPresence::DOWN) {
            return null;
        }

        // An address is what makes the refusal actionable, and UP cannot arrive without one; a
        // missing address is therefore the same "could not check" case, whatever the probe said.
        if ($presence === DaemonPresence::UP && $address !== null) {
            echo "{$command} writes state the daemon owns; the daemon is answering on {$address}."
                . " Stop the daemon and run it again.\n";

            return ExitCode::ERROR;
        }

        echo "{$command} writes state the daemon owns and cannot check whether the daemon is running: "
            . self::UNCHECKABLE_DETAIL . "\n";

        return ExitCode::CONFIG_ERROR;
    }
}
