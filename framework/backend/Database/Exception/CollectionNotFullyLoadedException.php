<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\Object\Objects;
use Hilos\HilosException;

/**
 * Exception: a collection is walked as a set while it holds only part of one.
 *
 * A lazy collection that loads by key answers a walk with the rows somebody happened to ask for,
 * which reads exactly like a complete answer and is not one. The message names the collection,
 * so the caller learns which set was incomplete rather than that something somewhere was lazy.
 *
 * Completeness is declared by {@see Objects::preloadAll()}, which is what the holder of a
 * collection calls; every other process stays lazy and has no business walking it.
 */
final class CollectionNotFullyLoadedException extends HilosException
{
}
