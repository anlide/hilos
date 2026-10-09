<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Auth\Throttle\ThrottlePolicy;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * SecondFactorLockPolicy - the numbers behind the ceiling on wrong app codes (HIL-1285).
 *
 * A policy object in the way {@see ThrottlePolicy} is one: read from the environment
 * ({@see fromEnv()}) and asked from there on, so the arithmetic can be exercised on chosen
 * numbers without an environment behind it. It holds no state - what has happened to a person
 * lives on their row of `hilos_second_factor_setting`.
 *
 * It answers how many wrong app codes a day locks the app codes ({@see misses()}), in how long
 * a window they are counted ({@see windowSeconds()}), which step of the ladder the next lock
 * takes ({@see nextStep()}) and how long that step holds ({@see lockSecondsFor()}), and how the
 * time left reads in the refusal ({@see waitText()}).
 *
 * The ladder does not run off its own end: a lock past the last step stays on it, and the last
 * duration repeats - so the step a row keeps never outgrows the ladder, however long a guesser
 * keeps at it. A day without a lock starts it again from the first step, as a day of quiet
 * forgives the throttle's ladder. The window and that day are deliberately not configurable: a
 * shortened window would hand a patient guesser its attempts back.
 */
final class SecondFactorLockPolicy
{
    /** Separates the configured ladder steps in the env value. */
    private const string STEP_SEPARATOR = ',';

    /** Wrong app codes per window when the configured value is not a positive number. */
    private const int FALLBACK_MISSES = 10;

    /** Ladder used when the configured value parses to no usable step at all. */
    private const array FALLBACK_STEPS = [900, 3600, 21600, 86400];

    /** Length of the window the wrong app codes are counted in. */
    private const int WINDOW_SECONDS = TimeConstants::SECONDS_PER_DAY;

    /** Time after the end of a lock without a new one that starts the ladder again. */
    private const int STEP_COOLDOWN_SECONDS = TimeConstants::SECONDS_PER_DAY;

    /** Time left under an hour, in whole minutes rounded up. */
    private const string WAIT_MINUTES = '%d min';

    /** Time left of an hour or more, in whole hours rounded up. */
    private const string WAIT_HOURS = '%d h';

    /**
     * @param int $misses Wrong app codes in a window that lock the app codes
     * @param list<int> $steps Lock durations in seconds, one per step of the ladder
     */
    public function __construct(
        private readonly int $misses,
        private readonly array $steps,
    ) {
    }

    /**
     * Reads the policy from the environment, falling back on a value that is not usable.
     *
     * A ceiling of zero would lock the app codes on the first wrong one, and a ladder of no
     * usable step would count misses and never lock - so a misconfigured deployment gets the
     * documented numbers instead. A ceiling that is not a whole number at all is refused by the
     * environment, as every integer value is.
     *
     * @return self Policy built from the environment
     * @throws EnvException When a lock value cannot be read as its cataloged type
     */
    public static function fromEnv(): self
    {
        $env = Hilos::$env;
        if ($env === null) {
            return new self(self::FALLBACK_MISSES, self::FALLBACK_STEPS);
        }

        $misses = $env[EnvConstants::HILOS_SECOND_FACTOR_LOCK_MISSES]->int();

        return new self(
            $misses > 0 ? $misses : self::FALLBACK_MISSES,
            self::parseSteps($env[EnvConstants::HILOS_SECOND_FACTOR_LOCK_STEPS]->string()),
        );
    }

    /**
     * Wrong app codes in one window that lock the app codes.
     *
     * @return int The ceiling
     */
    public function misses(): int
    {
        return $this->misses;
    }

    /**
     * Length of the window the wrong app codes are counted in; the first miss opens it.
     *
     * @return int Seconds
     */
    public function windowSeconds(): int
    {
        return self::WINDOW_SECONDS;
    }

    /**
     * The step of the ladder the next lock takes, never past the last one.
     *
     * The step after the last lock, if that lock ended less than a day ago; otherwise the
     * first one - a person who never was locked, or kept clear of the ceiling for a day after
     * the last lock, starts again from the shortest. At the end of the ladder the step stays
     * where it is, as {@see ThrottlePolicy::escalate()} stays: a step that went on growing lock
     * after lock would outgrow the column that keeps it, and the lock would stop being put.
     *
     * @param int $lastStep Step of the last lock, 0 when there was none
     * @param ?int $lastLockedUntilSec End of the last lock (Unix seconds), or null when there was none
     * @param int $nowSec Now (Unix seconds)
     * @return int Step of the next lock, from 1
     */
    public function nextStep(int $lastStep, ?int $lastLockedUntilSec, int $nowSec): int
    {
        if ($lastStep < 1 || $lastLockedUntilSec === null || $nowSec - $lastLockedUntilSec >= self::STEP_COOLDOWN_SECONDS) {
            return 1;
        }

        return min($lastStep + 1, count($this->steps));
    }

    /**
     * How long a step of the ladder locks the app codes; a step past the end - one kept from a
     * longer ladder configured before - takes the last one.
     *
     * @param int $step Step of the lock, from 1
     * @return int Seconds the lock lasts
     */
    public function lockSecondsFor(int $step): int
    {
        return $this->steps[min(max($step, 1), count($this->steps)) - 1];
    }

    /**
     * The time left of a lock, as the refusal names it: minutes under an hour, hours from one up.
     *
     * Both round up, so the person is never told a lock is over before it is; a lock about to
     * end still reads one minute.
     *
     * @param int $seconds Time left
     * @return string Time left in words
     */
    public function waitText(int $seconds): string
    {
        if ($seconds < TimeConstants::SECONDS_PER_HOUR) {
            return sprintf(self::WAIT_MINUTES, max(1, (int)ceil($seconds / TimeConstants::SECONDS_PER_MINUTE)));
        }

        return sprintf(self::WAIT_HOURS, (int)ceil($seconds / TimeConstants::SECONDS_PER_HOUR));
    }

    /**
     * Parses the comma-separated ladder, dropping anything that is not a positive duration.
     *
     * @param string $configured Comma-separated seconds as configured
     * @return list<int> Lock durations in order of the steps
     */
    private static function parseSteps(string $configured): array
    {
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
