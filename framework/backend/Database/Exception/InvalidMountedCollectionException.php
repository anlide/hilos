<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\HilosException;

/** A mounted database collection has an invalid key or class chain. */
final class InvalidMountedCollectionException extends HilosException
{
}
