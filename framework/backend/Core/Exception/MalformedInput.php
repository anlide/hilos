<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

/**
 * Marker for an exception that means the input which arrived could not be parsed:
 * a line that does not decode, a payload that decodes into the wrong shape, a frame
 * whose opcode no reader knows, an HTTP request where a handshake was expected.
 *
 * Implementing it is the whole declaration; there is no method to write. A guard on
 * a read path asks whether the failure carries this marker rather than matching the
 * class against a list it keeps, so a new parsing failure joins the answer by
 * declaring the same contract, without the guard knowing its name.
 *
 * It says what the failure IS, not whose fault it is. An input refused by the format
 * exceptions reaches them both from the wire and from a payload assembled inside the
 * master process, so a reader that took the marker for "the client is to blame" would
 * be wrong about the second case. What the marker does license is the judgement that
 * the failure is routine for an open port: refusing input is the daily work of one,
 * and a stream of error lines about it would spend the error level on the ordinary
 * and leave nothing that stands out when the node itself is broken.
 */
interface MalformedInput
{
}
