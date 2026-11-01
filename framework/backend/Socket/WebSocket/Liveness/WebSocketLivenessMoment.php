<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Liveness;

/**
 * A moment of a browser socket's liveness that a test-only command brings to now.
 *
 * The command moves a moment, not the clock: the two silences put the last frame the browser
 * sent exactly their span back, and every deadline counted from that frame moves with it; the
 * check puts the next comparison against the connection's runtime row at now. The cases are
 * backed by strings because the command names them on its line.
 */
enum WebSocketLivenessMoment: string
{
    /** The silence reaches the probably-offline span at now. */
    case ProbablyOffline = 'probably-offline';

    /** The silence reaches the close timeout at now. */
    case Close = 'close';

    /** The flag is due to be checked against the connection's runtime row at now. */
    case Check = 'check';
}
