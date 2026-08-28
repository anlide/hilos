<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\Context\DbContext;
use Hilos\HilosException;

/**
 * Exception: a mounted database collection may not be read from this process yet.
 *
 * Not the same defect as {@see CollectionNotFoundException}: the collection exists and is
 * mounted, but nothing here has registered as its reader, or it has and the master's word that
 * the frames are addressed here has not arrived. Both are cured by wiring, not by retrying the
 * read, and the two messages say which wiring is missing.
 *
 * That a database read can be refused at all is the point of the guard rather than an oddity of
 * it: the rows would come back, and they would come back stale from the next write onwards,
 * because a process nobody addresses is a process nothing tells. A refusal names the wiring; a
 * silent answer names nothing and outlives the request.
 *
 * Raised by the read guard in {@see DbContext::__get()}, which is the one place a collection is
 * handed out to application code; the delivery paths reach their collections by their own
 * accessors and are not judged by it.
 */
class DbCollectionNotReadableException extends HilosException
{
}
