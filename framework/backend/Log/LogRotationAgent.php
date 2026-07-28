<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Utils\Exception\LogRotationException;

/**
 * Per-node worker agent owning the runtime log rotation trigger (HIL-379).
 *
 * A concrete framework agent: projects activate it by registering it in Hilos::AGENTS under
 * {@see HilosAgentType::HILOS_LOG_ROTATION}; its daemon opts out of cluster leadership so it runs
 * on every node (logs are node-local). It keeps the file I/O off the master loop by living in a
 * worker.
 *
 * {@see onTick()} cheaply checks the {@see LogRotationTriggerPolicy} (elapsed since the last
 * rotation, plus the summed live *.log size) at most once per {@see self::CHECK_INTERVAL_SECONDS};
 * when it fires it calls {@see LogRotator::rotate()}. The last-rotation timestamp is held in the
 * agent (worker-process-local, per node). Rotation failures are best-effort: logged, never fatal.
 * With both env thresholds at 0 the policy is inert and the daemon's start-only rotation stands.
 */
final class LogRotationAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_ROTATION;

    /** @var float Minimum seconds between trigger checks, so onTick stays well under 0.1s */
    private const float CHECK_INTERVAL_SECONDS = 5.0;

    private LogRotationTriggerPolicy $policy;
    private LogRotator $rotator;

    /** @var float Monotonic-ish timestamp (microtime) of the last rotation, the age-trigger baseline */
    private float $lastRotationAt = 0.0;

    /** @var float Timestamp of the last trigger check, for throttling */
    private float $lastCheckAt = 0.0;

    /**
     * Loads the policy and rotator and anchors the age baseline.
     *
     * The daemon already rotated the live logs on start, so the baseline is now.
     */
    public function onStart(): void
    {
        $this->policy = LogRotationTriggerPolicy::fromEnv();
        $this->rotator = LogRotator::fromEnv();
        $now = microtime(true);
        $this->lastRotationAt = $now;
        $this->lastCheckAt = $now;
    }

    /**
     * Throttled trigger check; rotates when the age or size criterion is crossed.
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

        if (!$this->policy->shouldRotate($now - $this->lastRotationAt, $this->rotator->liveLogBytes())) {
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
     * Nothing owned to release: the trigger state is process-local and dies with the worker.
     */
    public function onStop(): void
    {
        // No-op.
    }
}
