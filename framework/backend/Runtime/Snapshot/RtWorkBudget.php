<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Hilos\Core\Exception\InvalidArgumentException;

/** A consumable allowance, shared by every consumer using this lane in a pass. */
final class RtWorkBudget
{
    public const int DEFAULT_STEPS = 128;
    public const int DEFAULT_BYTES = 65536;

    private int $usedSteps = 0;
    private int $usedBytes = 0;
    private bool $closed = false;

    /**
     * @param int $steps Structural allowance
     * @param int $bytes Logical byte allowance
     * @param ?self $shared Shared free allowance, supplied only by the pass owner
     * @throws InvalidArgumentException When an allowance is negative
     */
    public function __construct(private int $steps = self::DEFAULT_STEPS, private int $bytes = self::DEFAULT_BYTES, private ?self $shared = null)
    {
        if ($steps < 0 || $bytes < 0) {
            throw new InvalidArgumentException('RT work allowances cannot be negative');
        }
    }

    /**
     * Reserves before execution. A byte request may be split even inside one leaf.
     *
     * @param int $bytes Maximum bytes requested; zero means a structural step only
     * @return ?int Granted bytes, or null when this pass cannot advance
     * @throws InvalidArgumentException When a request is negative
     */
    public function take(int $bytes = 0): ?int
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('RT work requests cannot be negative');
        }
        if ($this->closed) {
            return null;
        }
        if ($this->steps === 0 && $this->shared !== null && $this->shared->steps > 0) {
            --$this->shared->steps;
            ++$this->steps;
        }
        if ($bytes > 0 && $this->bytes === 0 && $this->shared !== null) {
            $borrowed = min($bytes, $this->shared->bytes);
            $this->shared->bytes -= $borrowed;
            $this->bytes += $borrowed;
        }
        if ($this->steps === 0 || ($bytes > 0 && $this->bytes === 0)) {
            return null;
        }
        $granted = min($bytes, $this->bytes);
        --$this->steps;
        $this->bytes -= $granted;
        ++$this->usedSteps;
        $this->usedBytes += $granted;

        return $granted;
    }

    /**
     * Closes this lane and makes only its unused share available for borrowing.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->shared !== null) {
            $this->shared->steps += $this->steps;
            $this->shared->bytes += $this->bytes;
        }
        $this->steps = 0;
        $this->bytes = 0;
    }

    /** @return int Steps actually reserved in this lane */
    public function usedSteps(): int
    {
        return $this->usedSteps;
    }

    /** @return int Logical bytes actually reserved in this lane */
    public function usedBytes(): int
    {
        return $this->usedBytes;
    }
}
