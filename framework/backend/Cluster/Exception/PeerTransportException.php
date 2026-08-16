<?php

declare(strict_types=1);

namespace Hilos\Cluster\Exception;

use Hilos\Core\Exception\MalformedInput;
use Hilos\Socket\SocketException;

/**
 * Thrown when a peer channel frame cannot be parsed or the handshake is
 * rejected — malformed JSON, an unknown frame type, a missing identity field,
 * or an incompatible protocol version. Socket-level failures keep using
 * {@see SocketException}; this covers the peer protocol layer above it.
 *
 * Carries {@see MalformedInput} for every one of those reasons: a frame from another
 * node is input like any other, and the node reading it is no more broken for having
 * refused it than a port is for refusing a client.
 */
class PeerTransportException extends ClusterException implements MalformedInput
{
}
