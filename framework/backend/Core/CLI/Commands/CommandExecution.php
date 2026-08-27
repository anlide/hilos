<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliApplication;
use Hilos\Core\CLI\CliManager;

/**
 * CommandExecution - one command's answer to "where does your work happen, and why there".
 *
 * Returned by {@see CommandInterface::execution()} and collected registry-wide by
 * {@see CliManager::executions()}. {@see CliApplication} reads the site to decide whether the
 * command may run at all; a guard test reads the reason to refuse a departure nobody explained.
 *
 * The reason belongs to the DEPARTURES and not to the rule. {@see CommandExecutionSite::DAEMON}
 * is what every command is expected to be, so it owes no explanation and carries null - the
 * three named constructors below make the difference impossible to get wrong, because the only
 * way to build a departure is to pass its reason as an argument.
 */
final readonly class CommandExecution
{
    /**
     * @param CommandExecutionSite $site Process the command's work happens in
     * @param ?string $reason Why the work happens there; null only for the daemon rule itself
     */
    private function __construct(
        public CommandExecutionSite $site,
        public ?string $reason,
    ) {
    }

    /**
     * Declares the rule: the daemon does the work, this process initiates it and prints the reply.
     *
     * @return self Execution declaring {@see CommandExecutionSite::DAEMON}
     */
    public static function daemon(): self
    {
        return new self(CommandExecutionSite::DAEMON, null);
    }

    /**
     * Declares a child process the daemon spawns itself, with no operator at its entrance.
     *
     * @param string $reason Why the daemon spawns this work instead of doing it in its loop
     * @return self Execution declaring {@see CommandExecutionSite::DAEMON_SPAWNED}
     */
    public static function daemonSpawned(string $reason): self
    {
        return new self(CommandExecutionSite::DAEMON_SPAWNED, $reason);
    }

    /**
     * Declares work that runs in the CLI process and changes nothing.
     *
     * @param string $reason Why the reading happens here rather than in the daemon
     * @return self Execution declaring {@see CommandExecutionSite::CLI_READ}
     */
    public static function cliRead(string $reason): self
    {
        return new self(CommandExecutionSite::CLI_READ, $reason);
    }

    /**
     * Declares work that runs in the CLI process and WRITES state the daemon owns.
     *
     * The only site the CLI spine gates: a command declaring it is refused while the daemon
     * answers on the command channel.
     *
     * @param string $reason Why the writing happens here rather than in the daemon
     * @return self Execution declaring {@see CommandExecutionSite::CLI_OFFLINE_WRITE}
     */
    public static function cliOfflineWrite(string $reason): self
    {
        return new self(CommandExecutionSite::CLI_OFFLINE_WRITE, $reason);
    }
}
