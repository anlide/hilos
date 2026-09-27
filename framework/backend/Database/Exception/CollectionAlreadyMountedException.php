<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;

/**
 * Exception: a second view is mounted under a collection key that already carries one.
 *
 * Raised by {@see DbContext::setRepresent()} on the second call for the same key, in any
 * context and for any key. Until it was, the second call overwrote the first in silence, which is
 * how a project could swap a framework chain for its own by re-mounting the key after
 * `parent::configure()` - a mount that held by accident of order and said nothing the day the
 * framework re-registered the key. A framework key is extended through
 * {@see HilosDbContext::frameworkExtensions()} instead, and the message says so.
 */
final class CollectionAlreadyMountedException extends HilosException
{
}
