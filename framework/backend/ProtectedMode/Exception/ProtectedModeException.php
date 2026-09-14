<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\Exception;

use Hilos\HilosException;
use Hilos\ProtectedMode\ProtectedModeExecutor;

/**
 * Base exception for the protected-mode subsystem (HIL-482).
 *
 * The freeze transitions themselves do not throw this family: they write a runtime row and
 * raise the RT family when that write is refused ({@see ProtectedModeExecutor}). What lands
 * here is the subsystem's own startup and filesystem contract: whether the connection roster
 * can carry browser sessions and whether the freeze state kept on disk can be trusted.
 */
class ProtectedModeException extends HilosException
{
}
