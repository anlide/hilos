<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Immutable trigger policy for log rotation (HIL-379, HIL-380).
 *
 * A value object over three independent axes — a planned cron schedule, a maximum age since the
 * last rotation, and a maximum summed size of the live logs. The two numeric axes are evaluated
 * by the pure predicate {@see shouldRotate()}; the schedule axis is materialized as a
 * {@see CronRule} by {@see createCronRule()} and evaluated by the caller. Any axis firing rotates;
 * a numeric axis of 0 is disabled and an empty (or malformed) cron expression disables the
 * schedule. All axes off means the policy never fires, preserving the start-only rotation the
 * daemon does on boot. A value the environment cannot answer disables only its own axis and is
 * named in {@see $unreadable}; the other axes keep the values they were configured with.
 */
final class LogRotationTriggerPolicy
{
    /** Name of the cron rule the schedule axis builds. */
    private const string CRON_RULE_NAME = 'log-rotation';

    /**
     * @param int $maxAgeSeconds Elapsed-since-last-rotation threshold in seconds; 0 disables the age axis
     * @param int $maxLiveSizeBytes Summed live *.log size threshold in bytes; 0 disables the size axis
     * @param ?string $cronExpression Five-field cron expression for the schedule axis; empty or
     *                                malformed disables it, null means no expression was configured
     * @param array<string, string> $unreadable Reason by environment variable name for every axis
     *                                          whose value could not be read; empty when the
     *                                          environment answered
     */
    public function __construct(
        public readonly int $maxAgeSeconds,
        public readonly int $maxLiveSizeBytes,
        public readonly ?string $cronExpression = null,
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * Builds the policy from the environment, clamping negative numeric thresholds to 0 (disabled).
     *
     * Each axis is read on its own, so a value the environment cannot answer leaves that axis
     * disabled and named in {@see $unreadable} while the other two stay configured. No access to
     * the environment at all disables every axis silently — that is the documented contract of
     * this class, not a failure worth naming.
     *
     * @return self Policy carrying the configured age, size, and schedule axes
     */
    public static function fromEnv(): self
    {
        $unreadable = [];
        $maxAge = self::envInt(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS, $unreadable);
        $maxSize = self::envInt(EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES, $unreadable);
        $cron = self::envString(EnvConstants::LOG_ROTATION_CRON, $unreadable);

        return new self($maxAge, $maxSize, $cron, $unreadable);
    }

    /**
     * Whether at least one axis is enabled.
     *
     * Lets the caller skip the whole tick when rotation is fully disabled (both numeric axes 0 and
     * no valid schedule).
     *
     * @return bool True when the age, size, or schedule axis is configured
     */
    public function isActive(): bool
    {
        return $this->maxAgeSeconds > 0 || $this->maxLiveSizeBytes > 0 || $this->hasSchedule();
    }

    /**
     * Materializes the schedule axis as a cron rule.
     *
     * @return ?CronRule Rule carrying the configured expression, or null when it is empty or not a
     *                   schedule {@see CronRule} can run
     */
    public function createCronRule(): ?CronRule
    {
        $expression = $this->scheduleExpression();
        if ($expression === null) {
            return null;
        }

        return new CronRule(self::CRON_RULE_NAME, $expression);
    }

    /**
     * Whether the age axis is enabled and its threshold is crossed.
     *
     * @param float $elapsedSeconds Seconds elapsed since the last rotation
     * @return bool True when age rotation should happen
     */
    public function ageExceeded(float $elapsedSeconds): bool
    {
        return $this->maxAgeSeconds > 0 && $elapsedSeconds >= $this->maxAgeSeconds;
    }

    /**
     * Whether the size axis is enabled and its threshold is crossed.
     *
     * @param int $liveBytes Summed size in bytes of the live *.log files
     * @return bool True when size rotation should happen
     */
    public function sizeExceeded(int $liveBytes): bool
    {
        return $this->maxLiveSizeBytes > 0 && $liveBytes >= $this->maxLiveSizeBytes;
    }

    /**
     * Pure predicate over the two numeric axes: whether the live logs should be rotated now.
     *
     * Fires when an enabled criterion is crossed — age at or beyond {@see $maxAgeSeconds}, or
     * live size at or beyond {@see $maxLiveSizeBytes}. Disabled criteria (0) never fire. The
     * schedule axis is not part of this predicate; the caller evaluates {@see createCronRule()}.
     *
     * @param float $elapsedSeconds Seconds elapsed since the last rotation
     * @param int $liveBytes Summed size in bytes of the live *.log files
     * @return bool True when rotation should happen
     */
    public function shouldRotate(float $elapsedSeconds, int $liveBytes): bool
    {
        return $this->ageExceeded($elapsedSeconds) || $this->sizeExceeded($liveBytes);
    }

    /**
     * Whether the configured cron expression is a well-formed schedule.
     *
     * @return bool True when the expression is non-empty and runnable by {@see CronRule}
     */
    private function hasSchedule(): bool
    {
        return $this->scheduleExpression() !== null;
    }

    /**
     * Normalizes the configured expression down to the schedule the cron rule can run.
     *
     * Well-formedness is asked of {@see CronRule} itself, so an expression this policy accepts is
     * one the rule actually fires on — counting fields here accepted "0 3 * * abc" and then left
     * the schedule silently dead.
     *
     * @return ?string Trimmed expression, or null when none was configured, it is empty, or it is
     *                 not a schedule the cron rule can run
     */
    private function scheduleExpression(): ?string
    {
        if ($this->cronExpression === null) {
            return null;
        }

        $expression = trim($this->cronExpression);
        if ($expression === '' || !CronRule::isValidExpression($expression)) {
            return null;
        }

        return $expression;
    }

    /**
     * Reads one numeric axis, disabling it and recording why when the environment cannot answer.
     *
     * @param EnvConstants $key Environment variable backing this axis
     * @param array<string, string> $unreadable Accumulator the failure reason is added to, keyed by variable name
     * @return int Configured threshold clamped to 0 or more; 0 when the value could not be read
     */
    private static function envInt(EnvConstants $key, array &$unreadable): int
    {
        try {
            return max(0, Hilos::$env?->int($key) ?? 0);
        } catch (EnvException $exception) {
            $unreadable[$key->name] = $exception->getMessage();

            return 0;
        }
    }

    /**
     * Reads the schedule axis, disabling it and recording why when the environment cannot answer.
     *
     * @param EnvConstants $key Environment variable backing this axis
     * @param array<string, string> $unreadable Accumulator the failure reason is added to, keyed by variable name
     * @return ?string Configured expression, or null when none was configured or it could not be read
     */
    private static function envString(EnvConstants $key, array &$unreadable): ?string
    {
        try {
            return Hilos::$env?->string($key);
        } catch (EnvException $exception) {
            $unreadable[$key->name] = $exception->getMessage();

            return null;
        }
    }
}
