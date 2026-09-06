<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;

/**
 * Exception: an object layer was asked for a property it does not carry.
 *
 * The database twin of {@see RtItemPropertyNotFoundException}: the item is there and readable,
 * the name is not one of its fields. That is a question about the declaration and never about
 * the wiring or the query, which is why it has a species of its own — a reader may answer it
 * with a fallback and still not want to answer a failed read that way.
 *
 * Raised by {@see Object_::__get()}, which is where a name no subclass claimed ends up.
 */
class PropertyNotAccessibleException extends DatabaseException
{
}
