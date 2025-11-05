<?php

declare(strict_types=1);

namespace Hilos\Utils\Constants;

/**
 * SignalConstants - Signal type constants
 *
 * Defines standard signal type names used throughout the framework.
 */
class SignalConstants
{
    // WebSocket signal types
    public const string SIGNAL_HANDSHAKE = 'handshake';
    public const string SIGNAL_FRAME = 'frame';
    public const string SIGNAL_FRAME_BINARY = 'frame_binary';
    public const string SIGNAL_CLOSE = 'close';
}
