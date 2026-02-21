<?php

namespace Hilos\Database\Exception\View;

use Hilos\Database\Exception\CloneNotAllowedException as BaseCloneNotAllowedException;

/**
 * Exception: cloning is not allowed (e.g. DbContext cannot be cloned).
 */
class CloneNotAllowedException extends BaseCloneNotAllowedException
{
}
