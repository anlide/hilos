<?php

declare(strict_types=1);

namespace Hilos\Cluster\Exception;

use Hilos\Socket\SocketException;

/**
 * Thrown when a peer channel frame cannot be parsed or the handshake is
 * rejected — malformed JSON, an unknown frame type, a missing identity field,
 * or an incompatible protocol version. Socket-level failures keep using
 * {@see SocketException}; this covers the peer protocol layer above it.
 */
class PeerTransportException extends ClusterException
{
}
