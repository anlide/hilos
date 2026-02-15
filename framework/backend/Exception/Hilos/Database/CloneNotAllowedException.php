<?php

namespace Hilos\Exception\Hilos\Database;

use Hilos\Exception\Database\CloneNotAllowedException as BaseCloneNotAllowedException;

/**
 * Exception: cloning is not allowed (e.g. DbContext cannot be cloned).
 */
class CloneNotAllowedException extends BaseCloneNotAllowedException
{
}
