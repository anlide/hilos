<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * DaemonConstants - Daemon display constants.
 *
 * Contains constants for daemon status display values.
 */
final class DaemonConstants
{
    /** @var string Daemon status display: online */
    public const string STATUS_ONLINE = 'ONLINE';

    /** @var string Daemon status display: offline */
    public const string STATUS_OFFLINE = 'OFFLINE';

    /** @var string Display value when status is not available */
    public const string VALUE_NOT_AVAILABLE = 'N/A';
}
