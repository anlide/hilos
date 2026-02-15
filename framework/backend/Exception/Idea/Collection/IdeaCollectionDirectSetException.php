<?php

namespace Hilos\Exception\Idea\Collection;

use Hilos\Exception\Idea\IdeaCollectionException;

/**
 * Exception: Attempt to directly set Idea instance in collection
 *
 * @deprecated Idea layer is deprecated; use Hilos\Exception\Database\* instead.
 */
class IdeaCollectionDirectSetException extends IdeaCollectionException
{
}
