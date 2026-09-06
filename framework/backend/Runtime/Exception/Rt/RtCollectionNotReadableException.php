<?php

declare(strict_types=1);

namespace Hilos\Runtime\Exception\Rt;

use Hilos\Runtime\Exception\RtException;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\WiringRefusal;

/**
 * Exception: a mounted runtime collection may not be read from this process yet.
 *
 * Not the same defect as {@see RtCollectionNotFoundException}: the collection exists and is
 * mounted, but nothing here has registered as its reader, or it has and the initial state is
 * still on its way. Both are cured by wiring, not by retrying the read, and the two messages
 * say which wiring is missing.
 *
 * Raised by the read guard in {@see RtContext::__get()}, which is the one place a collection is
 * handed out to application code; the delivery paths reach their collections by their own
 * accessors and are not judged by it.
 *
 * Stays inside the Rt family and carries {@see WiringRefusal} on top of it: the marker is what
 * lets a caller and the WIRING-REFUSAL-SWALLOWED guard name this failure together with its
 * database twin, which no shared base class could reach.
 */
class RtCollectionNotReadableException extends RtException implements WiringRefusal
{
}
