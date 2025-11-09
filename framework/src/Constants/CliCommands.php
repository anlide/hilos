<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * CliCommands - CLI command name constants
 *
 * Defines all available CLI command names used throughout the framework.
 * Centralized command name management prevents typos and ensures consistency.
 */
class CliCommands
{
    /** @var string Command: Show daemon status */
    public const string DAEMON_STATUS = 'daemon:status';

    /** @var string Command: Monitor daemon in real-time */
    public const string DAEMON_MONITOR = 'daemon:monitor';

    /** @var string Command: Show help information */
    public const string HELP = 'help';
}

