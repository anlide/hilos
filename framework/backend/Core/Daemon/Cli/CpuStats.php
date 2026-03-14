<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Cli;

/**
 * CpuStats - CPU statistics data holder.
 *
 * Represents CPU statistics from /proc/stat for comparison between measurements.
 * Stores user, nice, system, idle, and iowait values for CPU usage calculation.
 *
 * @package Hilos\Core\Daemon
 */
class CpuStats
{
    /** @var int User time */
    public int $user;

    /** @var int Nice time */
    public int $nice;

    /** @var int System time */
    public int $system;

    /** @var int Idle time */
    public int $idle;

    /** @var int I/O wait time */
    public int $iowait;

    /**
     * Creates CpuStats instance with CPU time values.
     *
     * @param int $user User time
     * @param int $nice Nice time
     * @param int $system System time
     * @param int $idle Idle time
     * @param int $iowait I/O wait time
     */
    public function __construct(
        int $user,
        int $nice,
        int $system,
        int $idle,
        int $iowait
    ) {
        $this->user = $user;
        $this->nice = $nice;
        $this->system = $system;
        $this->idle = $idle;
        $this->iowait = $iowait;
    }

    /**
     * Creates CpuStats instance from /proc/stat line.
     *
     * Parses /proc/stat output and creates CpuStats object.
     *
     * @param string $statLine Line from /proc/stat file
     * @return CpuStats CpuStats object
     */
    public static function fromProcStat(string $statLine): self
    {
        $parts = explode(' ', trim($statLine));

        return new self(
            (int)($parts[1] ?? 0),
            (int)($parts[2] ?? 0),
            (int)($parts[3] ?? 0),
            (int)($parts[4] ?? 0),
            (int)($parts[5] ?? 0),
        );
    }

    /**
     * Calculates idle time (idle + iowait).
     *
     * @return int Total idle time
     */
    public function getIdle(): int
    {
        return $this->idle + $this->iowait;
    }

    /**
     * Calculates non-idle time (user + nice + system).
     *
     * @return int Total non-idle time
     */
    public function getNonIdle(): int
    {
        return $this->user + $this->nice + $this->system;
    }

    /**
     * Calculates total time (idle + non-idle).
     *
     * @return int Total time
     */
    public function getTotal(): int
    {
        return $this->getIdle() + $this->getNonIdle();
    }

    /**
     * Calculates CPU usage percentage compared to previous stats.
     *
     * Compares current stats with previous stats and calculates CPU usage percentage.
     *
     * @param CpuStats $previous Previous CpuStats
     * @return ?float CPU usage percentage (0-100) or null if can't calculate
     */
    public function getUsagePercentage(self $previous): ?float
    {
        $prevIdle = $previous->getIdle();
        $prevNonIdle = $previous->getNonIdle();
        $prevTotal = $prevIdle + $prevNonIdle;

        $currentIdle = $this->getIdle();
        $currentNonIdle = $this->getNonIdle();
        $currentTotal = $currentIdle + $currentNonIdle;

        $totalDiff = $currentTotal - $prevTotal;
        $idleDiff = $currentIdle - $prevIdle;

        if ($totalDiff <= 0) {
            return null;
        }

        return (($totalDiff - $idleDiff) / $totalDiff) * 100;
    }
}
