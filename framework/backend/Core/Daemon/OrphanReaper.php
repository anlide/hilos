<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Utils\Logger;

/**
 * OrphanReaper - kills processes left behind by a daemon that died.
 *
 * Workers are spawned through {@see \Hilos\Core\Process}, which does not set FD_CLOEXEC,
 * so a worker inherits the daemon's listening sockets. When the daemon dies without
 * stopping its workers, a surviving worker keeps holding the daemon's ports and the
 * next daemon cannot bind them: the node never comes back, and the watchdog restarts
 * the daemon forever against a port it can never take.
 *
 * The reaper closes that hole from the watchdog side. Orphans of a dead daemon are
 * re-parented to PID 1, which inside the container is the watchdog itself, so every
 * leftover — workers and their own grandchildren alike, since re-parenting is flat —
 * shows up as a direct child of this process. Sweeping them before each daemon start
 * makes "the daemon starts with nobody else around" an invariant, which is why no
 * bind-retry or error-text heuristic is needed anywhere else.
 *
 * Scanning for children by PPID rather than signalling the whole process group is
 * deliberate: `kill(-1)` would also cut down unrelated processes wherever the watchdog
 * is not PID 1, while a PPID scan simply finds nothing there.
 */
class OrphanReaper
{
    /** Process state in /proc that marks an already-dead child awaiting its parent's wait(). */
    private const string STATE_ZOMBIE = 'Z';

    /** Poll interval between checks for a signalled process to disappear. */
    private const int POLL_INTERVAL_US = 100_000;

    /** How long a signalled process may take to exit before it is killed outright. */
    private const int TERM_GRACE_SECONDS = 5;

    /**
     * Finds the live child processes of the current process.
     *
     * @param ?int $excludePid Process to leave out, normally the daemon just started
     * @return array<int, string> Command line keyed by process id (empty when there are none)
     */
    public function findChildren(?int $excludePid = null): array
    {
        $selfPid = posix_getpid();
        $entries = @scandir('/proc');
        if ($entries === false) {
            Logger::warning('OrphanReaper: /proc is not readable, skipping the orphan scan');

            return [];
        }

        $children = [];
        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }

            $pid = (int) $entry;
            if ($pid === $selfPid || $pid === $excludePid) {
                continue;
            }

            $stat = $this->statOf($pid);
            if ($stat === null || $stat->parentPid !== $selfPid || $stat->isZombie) {
                continue;
            }

            $children[$pid] = $this->commandLineOf($pid);
        }

        return $children;
    }

    /**
     * Terminates every live child of this process, killing whatever refuses to exit.
     *
     * Blocking on purpose: the caller runs this while no daemon is alive, so there is
     * nothing to keep ticking, and letting a start race a surviving worker is exactly
     * the failure being prevented.
     *
     * @param ?int $excludePid Process to leave alone, normally the daemon just started
     * @return int Number of processes that had to be killed after refusing to terminate
     */
    public function reap(?int $excludePid = null): int
    {
        $children = $this->findChildren($excludePid);
        if ($children === []) {
            return 0;
        }

        foreach ($children as $pid => $cmdline) {
            Logger::warning("OrphanReaper: terminating orphan pid={$pid} cmd={$cmdline}");
            posix_kill($pid, SIGTERM);
        }

        $deadline = microtime(true) + self::TERM_GRACE_SECONDS;
        while (microtime(true) < $deadline) {
            $this->collectExited();
            $children = array_intersect_key($children, $this->findChildren($excludePid));
            if ($children === []) {
                return 0;
            }

            usleep(self::POLL_INTERVAL_US);
        }

        foreach ($children as $pid => $cmdline) {
            Logger::warning("OrphanReaper: orphan pid={$pid} ignored SIGTERM, killing it (cmd={$cmdline})");
            posix_kill($pid, SIGKILL);
        }

        return count($children);
    }

    /**
     * Reaps whatever children have already exited, so they stop occupying the table.
     *
     * A signalled child stays visible in /proc as a zombie until its parent collects it,
     * and inside a container that parent is this watchdog. Without this the sweep would
     * wait out the full grace period and then send SIGKILL to a process that is already
     * dead — on every single daemon start, forever.
     */
    private function collectExited(): void
    {
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // Draining the queue is the point; the exit status of an orphan is not ours to report.
        }
    }

    /**
     * Reads the parentage and liveness of a process from /proc.
     *
     * @param int $pid Process to inspect
     * @return ?ProcessStat Parsed entry, or null when the process is gone or unreadable
     */
    private function statOf(int $pid): ?ProcessStat
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return null;
        }

        // The comm field is parenthesised and may itself contain spaces, so the fields
        // after it are counted from the last ')' rather than from the start of the line.
        $afterComm = strrchr($stat, ')');
        if ($afterComm === false) {
            return null;
        }

        // Fields after comm: state, ppid, ...
        $fields = preg_split('/\s+/', trim(substr($afterComm, 1)));
        if ($fields === false || !isset($fields[0], $fields[1]) || !ctype_digit($fields[1])) {
            return null;
        }

        return new ProcessStat((int) $fields[1], $fields[0] === self::STATE_ZOMBIE);
    }

    /**
     * Reads the command line of a process for the log record.
     *
     * @param int $pid Process to inspect
     * @return string Command line with its NUL separators flattened, or a placeholder
     */
    private function commandLineOf(int $pid): string
    {
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
        if ($cmdline === false || $cmdline === '') {
            return '?';
        }

        return trim(str_replace("\0", ' ', $cmdline));
    }
}
