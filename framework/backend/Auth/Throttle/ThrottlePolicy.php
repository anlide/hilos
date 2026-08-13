<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Hilos;
use Hilos\Log\LogRotationTriggerPolicy;

/**
 * ThrottlePolicy - the configured numbers behind the anti-abuse decision (HIL-420).
 *
 * Read once when the throttle agent starts ({@see fromEnv()}) and asked from there on, the
 * way {@see LogRotationTriggerPolicy} is: a policy object rather than scattered env reads,
 * so the arithmetic can be exercised on chosen numbers without an environment behind it.
 *
 * It answers three questions and holds no state: how many attempts a scope is allowed in a
 * window ({@see maxFor()}), which ladder step a key moves to when it breaches
 * ({@see escalate()}), and how long that step blocks it ({@see blockSecondsFor()}). What has
 * happened to a given key lives in the runtime counters, not here.
 *
 * The ladder deliberately does not run off its own end: a key that breaches again while
 * already at the last step stays on that step. Design point 7 reserves the step beyond the
 * ladder for a challenge, and until that leaf exists there is nothing to escalate a client
 * to - the honest behavior is to keep refusing for the longest configured duration rather
 * than to invent a punishment the plan does not name.
 */
final class ThrottlePolicy
{
    /** Separates the configured ladder steps in the env value. */
    private const string STEP_SEPARATOR = ',';

    /** Ladder used when the configured value parses to no usable step at all. */
    private const array FALLBACK_STEPS = [30, 120, 600, 3600];

    /** Idle seconds after which an escalation level cools back to zero. */
    private const int LEVEL_COOLDOWN_SECONDS = 24 * TimeConstants::SECONDS_PER_HOUR;

    /**
     * @param bool $enabled Whether the layer refuses anything at all
     * @param float $windowSeconds Length of the fixed window attempts are counted in
     * @param int $maxPerSession Attempts one session may make on one action per window
     * @param int $maxPerIp Attempts one IP may make on one action per window
     * @param list<int> $steps Block durations in seconds, one per escalation step
     * @param int $verdictTimeoutMs Milliseconds a deferred action waits for a verdict
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly float $windowSeconds,
        public readonly int $maxPerSession,
        public readonly int $maxPerIp,
        public readonly array $steps,
        public readonly int $verdictTimeoutMs,
    ) {
    }

    /**
     * Reads the policy from the environment, clamping every value into a usable range.
     *
     * Each number is clamped at its reading site rather than trusted: a window of zero
     * would make every attempt the first one of a fresh window, and a limit of zero would
     * refuse the very first attempt from everyone, so a misconfigured deployment would
     * either disable the layer silently or lock every user out.
     *
     * @return self Policy built from the environment
     */
    public static function fromEnv(): self
    {
        $env = Hilos::$env;

        return new self(
            $env?->bool(EnvConstants::HILOS_AUTH_THROTTLE_ENABLED) ?? false,
            (float)max(1, $env?->int(EnvConstants::HILOS_AUTH_THROTTLE_WINDOW) ?? 0),
            max(1, $env?->int(EnvConstants::HILOS_AUTH_THROTTLE_MAX_SESSION) ?? 0),
            max(1, $env?->int(EnvConstants::HILOS_AUTH_THROTTLE_MAX_IP) ?? 0),
            self::parseSteps($env?->string(EnvConstants::HILOS_AUTH_THROTTLE_STEPS)),
            max(1, $env?->int(EnvConstants::HILOS_AUTH_THROTTLE_VERDICT_TIMEOUT_MS) ?? 0),
        );
    }

    /**
     * Attempts one throttle scope is allowed within a window.
     *
     * An IP is allowed more than a session on purpose: everyone behind one NAT shares it,
     * so the IP limit is a ceiling on a crowd while the session limit is a ceiling on one
     * browser.
     *
     * @param string $scope Throttle scope, one of {@see ThrottleScope}
     * @return int Attempts allowed in one window
     */
    public function maxFor(string $scope): int
    {
        return $scope === ThrottleScope::SESSION ? $this->maxPerSession : $this->maxPerIp;
    }

    /**
     * The ladder step a key moves to when it breaches its limit, never past the last one.
     *
     * @param int $level Level the key is at now
     * @return int Level the key moves to
     */
    public function escalate(int $level): int
    {
        return min($level + 1, count($this->steps));
    }

    /**
     * How long a level blocks a key.
     *
     * @param int $level Level the key reached; below one it is not blocked at all
     * @return int Seconds the block lasts, 0 when the level does not block
     */
    public function blockSecondsFor(int $level): int
    {
        if ($level < 1) {
            return 0;
        }

        return $this->steps[min($level, count($this->steps)) - 1];
    }

    /**
     * Idle time after which an escalation level cools back to zero.
     *
     * A day, and not configurable: the cooldown is what makes the ladder a memory of abuse
     * rather than a permanent record, and a deployment that shortened it to minutes would
     * hand a patient client its ladder back for free.
     *
     * @return float Seconds of quiet after which a level is forgotten
     */
    public function cooldownSeconds(): float
    {
        return (float)self::LEVEL_COOLDOWN_SECONDS;
    }

    /**
     * Parses the comma-separated ladder, dropping anything that is not a positive duration.
     *
     * Falls back to the documented ladder when nothing usable is configured, because a
     * throttle with no step to escalate to would count breaches and never act on them.
     *
     * @param ?string $configured Comma-separated seconds as configured, or null with no environment
     * @return list<int> Ladder steps in ascending order of severity
     */
    private static function parseSteps(?string $configured): array
    {
        if ($configured === null) {
            return self::FALLBACK_STEPS;
        }

        $steps = [];
        foreach (explode(self::STEP_SEPARATOR, $configured) as $step) {
            $seconds = (int)trim($step);
            if ($seconds > 0) {
                $steps[] = $seconds;
            }
        }

        return $steps === [] ? self::FALLBACK_STEPS : $steps;
    }
}
