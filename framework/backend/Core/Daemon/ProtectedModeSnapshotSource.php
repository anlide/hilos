<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Socket\Server\CommandServer;

/**
 * ProtectedModeSnapshotSource - master-side seam that reports this node's protected-mode state.
 *
 * The mirror of {@see ConnectionDropper} in role and in wiring: the freeze state a CLI wants
 * to read lives in the master process, so the request reaches the master over the command
 * channel and the master hands it to the daemon that owns both halves of the answer - the
 * runtime row and the agent roster the freeze stopped. The {@see DaemonManager} implements
 * this and is wired into the {@see CommandServer} at registration, so the command handler
 * never depends on the concrete manager.
 *
 * Answering in the master is the whole point rather than an implementation detail: during a
 * freeze every agent but the initiator is stopped, so an agent-answered inspector would fall
 * silent in exactly the phase it exists to report on.
 *
 * Test-only: the sole caller is the `test:protected-mode:inspect` command, used by e2e and by
 * the multi-node harness to assert on the freeze deterministically.
 */
interface ProtectedModeSnapshotSource
{
    /**
     * Reports this node's own view of protected mode.
     *
     * Reads in-memory state only - no database, no file and no socket I/O - because it runs
     * on the master's connection-accept path, where a blocking call stalls every client.
     *
     * @return array<string, mixed> Snapshot keyed by {@see ProtectedModeCommandConstants} fields
     */
    public function protectedModeSnapshot(): array;
}
