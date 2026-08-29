<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Environment\Exception\EnvException;
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
 *
 * The policy itself is rebuilt on every one of those checks (HIL-760), so an administrator editing
 * the thresholds is obeyed within seconds and without restarting the node. The schedule is the one
 * exception: its {@see CronRule} remembers when it last ran, so it is rebuilt only when the
 * expression itself changes — rebuilding it every check would restart that memory and the schedule
 * would never fire.
 */
final class LogRotationAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_ROTATION;

    /** @var float Minimum seconds between trigger checks, so onTick stays well under 0.1s */
    private const float CHECK_INTERVAL_SECONDS = 5.0;

    private LogRotationTriggerPolicy $policy;
    private LogRotator $rotator;
    private LogSettingsResolver $resolver;

    /** @var ?CronRule Schedule axis; null when no valid expression is configured */
    private ?CronRule $cronRule = null;

    /** @var ?string Expression the current schedule axis was built from, so it is rebuilt only on a change */
    private ?string $cronExpression = null;

    /** @var float Monotonic-ish timestamp (microtime) of the last rotation, the age-trigger baseline */
    private float $lastRotationAt = 0.0;

    /** @var float Timestamp of the last trigger check, for throttling */
    private float $lastCheckAt = 0.0;

    /**
     * Loads the rotator, takes the first policy, and anchors the age baseline.
     *
     * The daemon already rotated the live logs on start, so the baseline is now. A non-empty but
     * unparseable cron expression disables the schedule axis only and is logged once.
     *
     * @throws EnvException When a rotator env key is missing, outside the catalog, or of the
     *                      wrong type
     */
    public function onStart(): void
    {
        $this->rotator = LogRotator::fromEnv();
        $this->resolver = new LogSettingsResolver();
        $this->refreshPolicy();

        $now = microtime(true);
        $this->lastRotationAt = $now;
        $this->lastCheckAt = $now;
    }

    /**
     * Throttled trigger check; re-reads the policy, then rotates when an axis fires.
     */
    public function onTick(): void
    {
        $now = microtime(true);
        if ($now - $this->lastCheckAt < self::CHECK_INTERVAL_SECONDS) {
            return;
        }
        $this->lastCheckAt = $now;

        $this->refreshPolicy();

        if (!$this->policy->isActive()) {
            return;
        }

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
     * Re-reads the policy from the settings and re-arms the schedule axis when it changed.
     *
     * Whatever the resolver had to complain about goes to the journal here, and it only speaks when
     * the outcome changed, so a value that stays wrong does not fill the log it configures.
     */
    private function refreshPolicy(): void
    {
        $this->policy = $this->resolver->rotationPolicy();

        while (($complaint = $this->resolver->takeComplaint()) !== null) {
            $this->logAgentError($complaint);
        }

        // Compared by expression and not by "is there a rule": an expression that yields no rule is
        // refused once, and re-deciding it every check would report the same refusal every check.
        $expression = $this->policy->cronExpression;
        if ($expression === $this->cronExpression) {
            return;
        }

        $this->cronExpression = $expression;
        $this->cronRule = $this->policy->createCronRule();
        if ($this->cronRule === null && $expression !== null && trim($expression) !== '') {
            $this->logAgentError("Log rotation: ignoring invalid cron expression '{$expression}'");
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
