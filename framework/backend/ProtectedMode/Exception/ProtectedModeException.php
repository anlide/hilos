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
 * here is the subsystem's own contract with the disk it keeps the freeze on, which is the one
 * part of protected mode that outlives the process.
 */
class ProtectedModeException extends HilosException
{
}
