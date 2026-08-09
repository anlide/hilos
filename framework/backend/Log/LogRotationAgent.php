<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Utils\Exception\LogRotationException;

/**
 * Per-node worker agent owning the runtime log rotation trigger (HIL-379, HIL-380).
 *
 * A concrete framework agent: projects activate it by registering it in Hilos::AGENTS under
 * {@see HilosAgentType::HILOS_LOG_ROTATION} (marked per-node so it starts on every node); its
 * daemon opts out of cluster leadership so it runs on every node (logs are node-local). It keeps
 * the file I/O off the master loop by living in a worker.
 *
 * {@see onTick()} cheaply checks the {@see LogRotationTriggerPolicy} at most once per
 * {@see self::CHECK_INTERVAL_SECONDS}: the cron schedule, then the elapsed-since-last-rotation age
 * axis, and only then the summed live *.log size (the sole I/O). Any axis firing calls
 * {@see LogRotator::rotate()} and resets the shared baseline. The last-rotation timestamp is held
 * in the agent (worker-process-local, per node). Rotation failures are best-effort: logged, never
 * fatal. With no axis configured the policy is inert and the daemon's start-only rotation stands.
 */
final class LogRotationAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_ROTATION;

    /** @var float Minimum seconds between trigger checks, so onTick stays well under 0.1s */
    private const float CHECK_INTERVAL_SECONDS = 5.0;

    private LogRotationTriggerPolicy $policy;
    private LogRotator $rotator;

    /** @var ?CronRule Schedule axis, built once in onStart(); null when no valid expression is configured */
    private ?CronRule $cronRule = null;

    /** @var float Monotonic-ish timestamp (microtime) of the last rotation, the age-trigger baseline */
    private float $lastRotationAt = 0.0;

    /** @var float Timestamp of the last trigger check, for throttling */
    private float $lastCheckAt = 0.0;

    /**
     * Loads the policy and rotator, builds the schedule axis, and anchors the age baseline.
     *
     * The daemon already rotated the live logs on start, so the baseline is now. A non-empty but
     * unparseable cron expression disables the schedule axis only and is logged once.
     */
    public function onStart(): void
    {
        $this->policy = LogRotationTriggerPolicy::fromEnv();
        $this->rotator = LogRotator::fromEnv();
        $this->cronRule = $this->policy->createCronRule();
        $expression = $this->policy->cronExpression;
        if ($this->cronRule === null && $expression !== null && trim($expression) !== '') {
            $this->logAgentError("Log rotation: ignoring invalid cron expression '{$expression}'");
        }
        $now = microtime(true);
        $this->lastRotationAt = $now;
        $this->lastCheckAt = $now;
    }

    /**
     * Throttled trigger check; rotates when the schedule, age, or size axis fires.
     */
    public function onTick(): void
    {
        if (!$this->policy->isActive()) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastCheckAt < self::CHECK_INTERVAL_SECONDS) {
            return;
        }
        $this->lastCheckAt = $now;

        if (!$this->shouldRotate($now)) {
            return;
        }

        try {
            $moved = $this->rotator->rotate();
            $this->lastRotationAt = microtime(true);
            if ($moved > 0) {
                $this->logAgentInfo("Log rotation: moved {$moved} live log file(s)");
            }
        } catch (LogRotationException $exception) {
            // Best-effort: a failed rotation must never crash the agent; retry on the next check.
            $this->logAgentError('Log rotation failed: ' . $exception->getMessage());
        }
    }

    /**
     * Evaluates the axes in cheapest-first order, measuring the live logs only as a last resort.
     *
     * The schedule and age axes are free (a cron match and an elapsed-time comparison); the size
     * axis reads the live *.log sizes, so it runs only when neither of the first two fired.
     *
     * @param float $now Current microtime, the age-axis reference
     * @return bool True when any axis calls for a rotation now
     */
    private function shouldRotate(float $now): bool
    {
        if ($this->cronRule?->shouldRun() ?? false) {
            return true;
        }
        if ($this->policy->ageExceeded($now - $this->lastRotationAt)) {
            return true;
        }

        return $this->policy->sizeExceeded($this->rotator->liveLogBytes());
    }

    /**
     * Nothing owned to release: the trigger state is process-local and dies with the worker.
     */
    public function onStop(): void
    {
        // No-op.
    }
}
