<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

/**
 * CommandInterface - Interface for CLI commands
 *
 * Defines contract for all CLI command implementations.
 * Each command must implement execute method that returns exit code.
 */
interface CommandInterface
{
    /**
     * Execute command
     *
     * @param array $options Parsed command options (--key=value or --flag)
     * @param array $args Positional arguments
     * @return int Exit code (0 = success, non-zero = error)
     */
    public function execute(array $options, array $args): int;
}

