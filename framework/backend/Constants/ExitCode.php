<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * ExitCode - Exit code constants for process termination.
 *
 * Provides standardized exit codes for consistent process termination
 * across the entire framework. Based on Unix/Linux conventions.
 */
final class ExitCode
{
    /** @var int Success exit code */
    public const int SUCCESS = 0;

    /** @var int General error exit code */
    public const int ERROR = 1;

    /** @var int Invalid command or argument */
    public const int INVALID_ARGUMENT = 2;

    /** @var int Configuration error */
    public const int CONFIG_ERROR = 3;

    /** @var int Process not found */
    public const int PROCESS_NOT_FOUND = 4;

    /** @var int Permission denied */
    public const int PERMISSION_DENIED = 5;

    /** @var int Timeout occurred */
    public const int TIMEOUT = 6;

    /** @var int Terminated by Ctrl+C / SIGINT (128 + signal number 2) */
    public const int SIGNAL_RECEIVED = 130;
}
