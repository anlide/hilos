<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** Private deadlines of one supervised child; receipt time is supplied by its owner. */
final class DaemonHangMonitor
{
    private ?float $lastMarkAt = null;
    private ?float $stopAt = null;
    private bool $suspended = false;
    private bool $killed = false;

    /** @param float $now Monotonic receipt time of a bounded batch of valid marks */
    public function markReceived(float $now): void
    {
        if (!$this->suspended && $this->stopAt === null) {
            $this->lastMarkAt = $now;
        }
    }

    /** @return ?float Silence deadline, absent before the first mark or after a stop decision */
    public function staleAt(): ?float
    {
        return $this->lastMarkAt === null || $this->suspended || $this->stopAt !== null
            ? null : $this->lastMarkAt + MasterHeartbeat::STALE_SECONDS;
    }

    /**
     * @param float $now Monotonic observation
     * @return bool Whether the armed silence deadline has arrived
     */
    public function isStale(float $now): bool
    {
        $deadline = $this->staleAt();
        return $deadline !== null && $now >= $deadline;
    }

    /**
     * @param float $now Monotonic observation
     * @return float Silence duration for the incident record
     */
    public function silenceSeconds(float $now): float
    {
        return $this->lastMarkAt === null ? 0.0 : $now - $this->lastMarkAt;
    }

    /** @param float $now Monotonic time of the first hang stop request */
    public function beginStop(float $now): void
    {
        if (!$this->suspended && $this->lastMarkAt !== null && $this->stopAt === null) {
            $this->stopAt = $now + MasterHeartbeat::STOP_GRACE_SECONDS;
        }
    }

    /** @return bool Whether hang termination has already begun */
    public function isStopping(): bool
    {
        return $this->stopAt !== null;
    }

    /** @return ?float Kill deadline, absent before hang or after the single kill request */
    public function killAt(): ?float
    {
        return $this->killed ? null : $this->stopAt;
    }

    /** Retires the runnable deadline after SIGKILL; exit still belongs to Process. */
    public function markKilled(): void
    {
        if ($this->stopAt !== null) {
            $this->killed = true;
        }
    }

    /** Suppresses hang detection for normal control, retaining a grace already begun. */
    public function suspend(): void
    {
        $this->suspended = true;
    }

    /** Drops every fact of the previous child generation. */
    public function reset(): void
    {
        $this->lastMarkAt = null;
        $this->stopAt = null;
        $this->suspended = false;
        $this->killed = false;
    }

    /** @param float $now Bring only an already armed silence deadline to this monotonic time */
    public function bringStaleToNow(float $now): void
    {
        if ($this->staleAt() !== null) {
            $this->lastMarkAt = min($this->lastMarkAt, $now - MasterHeartbeat::STALE_SECONDS);
        }
    }

    /** @param float $now Bring only an active hang grace to this monotonic time */
    public function bringStopToNow(float $now): void
    {
        if ($this->killAt() !== null) {
            $this->stopAt = min($this->stopAt, $now);
        }
    }
}
