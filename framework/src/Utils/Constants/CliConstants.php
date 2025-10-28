<?php

declare(strict_types=1);

namespace Hilos\Utils\Constants;

/**
 * CLI Constants - constants for CLI interface
 *
 * All configurable values are read from environment variables (.env file)
 * with fallback to default values
 */
class CliConstants
{
    // Error message limits (not configurable)
    public const int ERROR_MESSAGE_MAX_LENGTH = 2000;
    public const int ERROR_STACK_TRACE_MAX_LENGTH = 10000;

    // HTTP Status endpoint path (not configurable)
    public const string HTTP_STATUS_PATH = '/status';
}
