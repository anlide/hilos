<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * SignalConstants - Signal name constants
 *
 * Defines standard signal names used throughout the framework.
 */
class SignalConstants
{
    /** @var string Workers ready signal - sent when initial workers are ready */
    public const string WORKERS_READY = 'workers_ready';
}
