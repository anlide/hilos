<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

use Hilos\Core\Process;

/**
 * BackupShipCommand - one transfer, spelled exactly as it will be spawned.
 *
 * Literally what goes into {@see Process::__construct()}: a binary and its argv entries, passed
 * verbatim with no shell in between, so nothing in a host name or a path can be read as syntax.
 *
 * Carrying the command as a value is what keeps the drivers testable: a driver builds one of
 * these and never runs it, so every argument a receiver will see is asserted in a unit test
 * without a network, a key, or a second machine.
 */
final class BackupShipCommand
{
    /**
     * @param string $binary Executable to spawn
     * @param list<string> $args Arguments passed verbatim as argv entries
     */
    public function __construct(
        public readonly string $binary,
        public readonly array $args,
    ) {
    }
}
