<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliApplication;

/**
 * CommandExecutionSite - the process a CLI command's work actually happens in.
 *
 * The project has one rule for commands: the DAEMON does the work, and the CLI process only
 * initiates it and prints the answer. That rule used to live nowhere - a reader had to open a
 * command and follow its body to learn whether it reached the daemon, opened the database
 * itself, or did both. Naming the site turns that reading into a declaration every command
 * makes about itself, and lets {@see CliApplication} act on it.
 *
 * Three of the four cases are departures from the rule, and each one is a decision somebody
 * made for a reason - which is why {@see CommandExecution} carries that reason for them and
 * why a guard test refuses a departure that does not state one.
 */
enum CommandExecutionSite: string
{
    /** The rule: the daemon does the work, the CLI process initiates it and prints the reply. */
    case DAEMON = 'daemon';

    /** A child process the daemon itself spawns, so heavy work does not run inside the loop. */
    case DAEMON_SPAWNED = 'daemon-spawned';

    /** Work in the CLI process that reads without changing state; runs whether the daemon is up or not. */
    case CLI_READ = 'cli-read';

    /** Work in the CLI process that WRITES state; admissible only while the daemon is not running. */
    case CLI_OFFLINE_WRITE = 'cli-offline-write';
}
