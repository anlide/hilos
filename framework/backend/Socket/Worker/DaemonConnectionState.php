<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker;

/**
 * DaemonConnectionState - State of the worker side of the daemon connection.
 *
 * State flow: IDLE → CONNECTING → CONNECTED → LOST.
 * LOST is terminal: the worker never reconnects, because the daemon is its
 * supervisor and starts a replacement worker instead.
 */
enum DaemonConnectionState: string
{
    /** @var string No connection attempt has been made yet */
    case IDLE = 'idle';

    /** @var string Non-blocking connect() started, completion not confirmed yet */
    case CONNECTING = 'connecting';

    /** @var string Connection established and usable */
    case CONNECTED = 'connected';

    /** @var string Connection lost for good; the worker must exit */
    case LOST = 'lost';
}
