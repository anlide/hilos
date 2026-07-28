<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Hilos;

/**
 * Immutable trigger policy for log rotation (HIL-379).
 *
 * A value object over two independent criteria — maximum age since the last rotation and
 * maximum summed size of the live logs — evaluated by the pure predicate {@see shouldRotate()}.
 * Either criterion firing rotates; a criterion of 0 is disabled. Both 0 means the policy never
 * fires, preserving the start-only rotation the daemon does on boot.
 */
final class LogRotationTriggerPolicy
{
    /**
     * @param int $maxAgeSeconds Elapsed-since-last-rotation threshold in seconds; 0 disables the age criterion
     * @param int $maxLiveSizeBytes Summed live *.log size threshold in bytes; 0 disables the size criterion
     */
    public function __construct(
        public readonly int $maxAgeSeconds,
        public readonly int $maxLiveSizeBytes,
    ) {
    }

    /**
     * Builds the policy from the environment, clamping negatives to 0 (disabled).
     *
     * @return self Policy carrying the configured age and size thresholds
     */
    public static function fromEnv(): self
    {
        return new self(
            max(0, Hilos::$env?->int(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS) ?? 0),
            max(0, Hilos::$env?->int(EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES) ?? 0),
        );
    }

    /**
     * Whether at least one criterion is enabled.
     *
     * Lets the caller skip measuring the live logs when rotation is fully disabled (both 0).
     *
     * @return bool True when the age or the size criterion is configured
     */
    public function isActive(): bool
    {
        return $this->maxAgeSeconds > 0 || $this->maxLiveSizeBytes > 0;
    }

    /**
     * Pure predicate: whether the live logs should be rotated now.
     *
     * Fires when an enabled criterion is crossed — age at or beyond {@see $maxAgeSeconds}, or
     * live size at or beyond {@see $maxLiveSizeBytes}. Disabled criteria (0) never fire.
     *
     * @param float $elapsedSeconds Seconds elapsed since the last rotation
     * @param int $liveBytes Summed size in bytes of the live *.log files
     * @return bool True when rotation should happen
     */
    public function shouldRotate(float $elapsedSeconds, int $liveBytes): bool
    {
        if ($this->maxAgeSeconds > 0 && $elapsedSeconds >= $this->maxAgeSeconds) {
            return true;
        }
        if ($this->maxLiveSizeBytes > 0 && $liveBytes >= $this->maxLiveSizeBytes) {
            return true;
        }
        return false;
    }
}
