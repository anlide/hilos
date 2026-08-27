<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliManager;
use Hilos\HilosException;
use JsonException;

/**
 * CommandInterface - Interface for CLI commands.
 *
 * Defines contract for all CLI command implementations.
 * Each command must implement execute method that returns exit code.
 *
 * An implementation's constructor must not touch the database or Hilos state. Every
 * command is constructed before the CLI bootstrap connects, because the answer of
 * {@see CliManager::requiresDatabase()} — read off the constructed command — is what
 * decides whether it connects at all. Work needing a connection belongs in execute().
 */
interface CommandInterface
{
    /**
     * Executes command with options and positional arguments.
     *
     * @param array<string, mixed> $options Parsed command options (--key=value or --flag)
     * @param list<string> $args Positional arguments
     * @return int Exit code (0 = success, non-zero = error)
     * @throws HilosException When the command refuses its input or its work fails
     * @throws JsonException When the command cannot encode its output
     */
    public function execute(array $options, array $args): int;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g., 'daemon:status')
     */
    public function getName(): string;

    /**
     * Declares where the command's work happens, and why there when it is not the daemon.
     *
     * The project rule is that the daemon does the work and the CLI process only initiates it;
     * a command that departs from it says so here and names the reason. The declaration is a
     * contract method rather than something read off the class hierarchy because reading it
     * that way needs Reflection, which this project forbids, and because a command outside the
     * framework's own class tree owes the same answer.
     *
     * @return CommandExecution Execution site of this command's work, with the reason for a departure
     */
    public function execution(): CommandExecution;

    /**
     * Returns short command description for command listing.
     *
     * @return string One-line description for command listing
     */
    public function getDescription(): string;

    /**
     * Returns detailed help text with usage examples.
     *
     * @return string Full help text with usage examples
     */
    public function getHelp(): string;
}
