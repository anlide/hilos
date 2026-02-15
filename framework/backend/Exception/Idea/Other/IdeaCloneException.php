<?php

namespace Hilos\Exception\Idea\Other;

use Hilos\Exception\Database\CloneNotAllowedException;

/**
 * Exception: Attempt to clone Idea singleton
 *
 * @deprecated Use Hilos\Exception\Database\CloneNotAllowedException or Hilos\Exception\Hilos\Database\CloneNotAllowedException instead.
 */
class IdeaCloneException extends CloneNotAllowedException
{
}
