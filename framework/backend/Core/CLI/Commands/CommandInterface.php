<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Core\CLI\CliManager;

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
     */
    public function execute(array $options, array $args): int;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g., 'daemon:status')
     */
    public function getName(): string;

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
