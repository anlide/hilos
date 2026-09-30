<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseGuarantee;
use Hilos\Database\DatabaseGuaranteeStartupGuard;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Exception: a project does not state what its database guarantees (HIL-1206).
 *
 * Every node relies on two promises about the database - one logical database for the whole
 * installation, and a read that sees what was written before it ({@see DatabaseGuarantee}) - and
 * the framework cannot tell a topology that keeps them from one that does not. So the project
 * states them in {@see Hilos::DATABASE_GUARANTEES}, and a facade that leaves any out is refused at
 * the start of the daemon rather than served wrong rows later.
 *
 * Raised by {@see DatabaseGuaranteeStartupGuard::assertDeclared()}. The message names every promise
 * missing at once, each with what it obliges: the reader is the author of the project, and one edit
 * answers all of them.
 */
final class UndeclaredDatabaseGuaranteeException extends HilosException
{
}
