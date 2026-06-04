<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Cron;

/**
 * Represents a single cron rule with cron expression.
 *
 * Stores cron job configuration: name, cron expression, and last execution time.
 * Supports standard cron expressions: "minute hour day month weekday".
 * Example: "*\/5 * * * *" means every 5 minutes.
 */
class CronRule
{
    /** Number of whitespace-separated fields in a cron expression. */
    private const int FIELD_COUNT = 5;

    /** Seconds per minute, used to derive minute-level run timestamps. */
    private const int SECONDS_PER_MINUTE = 60;

    /** Field separator inside a cron expression. */
    private const string FIELD_SEPARATOR = ' ';

    /** Token matching every value in a field. */
    private const string WILDCARD = '*';

    /** Step separator, as in every-N expressions like "*\/5". */
    private const string STEP_SEPARATOR = '/';

    /** Range separator, as in "1-5". */
    private const string RANGE_SEPARATOR = '-';

    /** List separator, as in "1,15,30". */
    private const string LIST_SEPARATOR = ',';

    // Inclusive value bounds per cron field.
    private const int MINUTE_MIN = 0;
    private const int MINUTE_MAX = 59;
    private const int HOUR_MIN = 0;
    private const int HOUR_MAX = 23;
    private const int DAY_MIN = 1;
    private const int DAY_MAX = 31;
    private const int MONTH_MIN = 1;
    private const int MONTH_MAX = 12;
    private const int WEEKDAY_MIN = 0;
    private const int WEEKDAY_MAX = 6;

    /** @var string Cron job name (unique identifier) */
    public readonly string $name;

    /** @var string Cron expression (minute hour day month weekday) */
    public string $expression;

    /** @var float Last execution timestamp */
    public float $lastRun;

    /**
     * Creates cron rule with name and expression.
     *
     * @param string $name Cron job name (unique identifier)
     * @param string $expression Cron expression (minute hour day month weekday)
     */
    public function __construct(string $name, string $expression)
    {
        $this->name = $name;
        $this->expression = $expression;

        // Store creation time as initial reference point (prevents immediate execution)
        // Use minute-level timestamp for consistent comparison
        $this->lastRun = floor(time() / self::SECONDS_PER_MINUTE);
    }

    /**
     * Check if cron job should run now.
     *
     * Checks if current time matches cron expression.
     * Only checks once per minute to avoid duplicate executions.
     *
     * @return bool True if should run
     */
    public function shouldRun(): bool
    {
        $currentTime = (float)time();

        // Don't run if already executed in current minute
        // Compare minute-level timestamps (lastRun is already stored at minute level)
        $currentMinuteTimestamp = floor($currentTime / self::SECONDS_PER_MINUTE);

        if ($this->lastRun === $currentMinuteTimestamp) {
            return false;
        }

        // Get current time components for cron expression matching
        $now = getdate();
        $currentMinute = (int)$now['minutes'];
        $currentHour = (int)$now['hours'];
        $currentDay = (int)$now['mday'];
        $currentMonth = (int)$now['mon'];
        $currentWeekday = (int)$now['wday']; // 0 = Sunday, 6 = Saturday

        // Parse and check cron expression
        $parts = $this->parseExpression();
        if (count($parts) !== self::FIELD_COUNT) {
            return false;
        }

        $matches = $this->matchesTime(
            $parts[0], // minute
            $parts[1], // hour
            $parts[2], // day
            $parts[3], // month
            $parts[4], // weekday
            $currentMinute,
            $currentHour,
            $currentDay,
            $currentMonth,
            $currentWeekday,
        );

        if ($matches) {
            $this->lastRun = $currentMinuteTimestamp;
            return true;
        }

        return false;
    }

    /**
     * Parse cron expression into parts.
     *
     * @return list<string> Five parts: minute, hour, day, month, weekday
     */
    private function parseExpression(): array
    {
        $parts = explode(self::FIELD_SEPARATOR, trim($this->expression));
        if (count($parts) !== self::FIELD_COUNT) {
            return [];
        }
        return $parts;
    }

    /**
     * Check if time matches cron expression part.
     *
     * @param string $part Cron expression part (e.g., "*\/5", "*", "15", "1-5")
     * @param int $value Current value to check
     * @param int $min Minimum value for this field
     * @param int $max Maximum value for this field
     * @return bool True if matches
     */
    private function matchesPart(string $part, int $value, int $min, int $max): bool
    {
        // Validate value is within field bounds first (early return)
        if ($value < $min || $value > $max) {
            return false;
        }

        // Trim whitespace once
        $part = trim($part);
        if ($part === '') {
            return false;
        }

        // Optimize: check most common cases first (wildcard and simple numbers)
        // Wildcard - matches all (most common case)
        if ($part === self::WILDCARD) {
            return true;
        }

        // Check for step value: */N or N-M/N
        $slashPos = strpos($part, self::STEP_SEPARATOR);
        if ($slashPos !== false) {
            $range = substr($part, 0, $slashPos);
            $stepStr = substr($part, $slashPos + 1);

            // Validate step is a positive integer
            if ($stepStr === '' || !ctype_digit($stepStr)) {
                return false;
            }
            $step = (int)$stepStr;
            if ($step <= 0) {
                return false;
            }

            if ($range === self::WILDCARD) {
                // */N - every N values starting from min
                // Safe: value is already validated to be >= min, so (value - min) >= 0
                return (($value - $min) % $step) === 0;
            }

            // N-M/N - range with step; a missing dash means an invalid step format
            $bounds = $this->parseRange($range, $min, $max);
            if ($bounds === null) {
                return false;
            }
            [$rangeMin, $rangeMax] = $bounds;

            // Check if value is within range
            if ($value >= $rangeMin && $value <= $rangeMax) {
                // Safe: value >= rangeMin, so (value - rangeMin) >= 0
                return (($value - $rangeMin) % $step) === 0;
            }
            return false;
        }

        // Check for range: N-M (before list check, as ranges are more common)
        if (str_contains($part, self::RANGE_SEPARATOR)) {
            $bounds = $this->parseRange($part, $min, $max);
            if ($bounds === null) {
                return false;
            }
            [$rangeMin, $rangeMax] = $bounds;

            return $value >= $rangeMin && $value <= $rangeMax;
        }

        // Check for list: N,M,K (less common, check after ranges)
        if (str_contains($part, self::LIST_SEPARATOR)) {
            $values = explode(self::LIST_SEPARATOR, $part);
            foreach ($values as $v) {
                $v = trim($v);
                if ($v === '' || !ctype_digit($v)) {
                    continue;
                }
                $intVal = (int)$v;
                // Optimize: check value match first (most likely to fail early)
                if ($intVal === $value && $intVal >= $min && $intVal <= $max) {
                    return true;
                }
            }
            return false;
        }

        // Single value - validate it's numeric (most common after wildcard)
        if (!ctype_digit($part)) {
            return false;
        }

        $intPart = (int)$part;
        if ($intPart < $min || $intPart > $max) {
            return false;
        }

        return $intPart === $value;
    }

    /**
     * Parse and validate an "N-M" range token against field bounds.
     *
     * @param string $range Range token such as "1-5"
     * @param int $min Minimum value allowed for this field
     * @param int $max Maximum value allowed for this field
     * @return array{int, int}|null [rangeMin, rangeMax], or null when malformed or out of bounds
     */
    private function parseRange(string $range, int $min, int $max): ?array
    {
        $dashPos = strpos($range, self::RANGE_SEPARATOR);
        if ($dashPos === false) {
            return null;
        }

        $rangeMinStr = substr($range, 0, $dashPos);
        $rangeMaxStr = substr($range, $dashPos + 1);

        if ($rangeMinStr === '' || $rangeMaxStr === '' ||
            !ctype_digit($rangeMinStr) || !ctype_digit($rangeMaxStr)) {
            return null;
        }

        $rangeMin = (int)$rangeMinStr;
        $rangeMax = (int)$rangeMaxStr;

        if ($rangeMin < $min || $rangeMax > $max || $rangeMin > $rangeMax) {
            return null;
        }

        return [$rangeMin, $rangeMax];
    }

    /**
     * Check if current time matches cron expression.
     *
     * @param string $minuteExpr Minute expression
     * @param string $hourExpr Hour expression
     * @param string $dayExpr Day expression
     * @param string $monthExpr Month expression
     * @param string $weekdayExpr Weekday expression
     * @param int $currentMinute Current minute
     * @param int $currentHour Current hour
     * @param int $currentDay Current day
     * @param int $currentMonth Current month
     * @param int $currentWeekday Current weekday
     * @return bool True if all parts match
     */
    private function matchesTime(
        string $minuteExpr,
        string $hourExpr,
        string $dayExpr,
        string $monthExpr,
        string $weekdayExpr,
        int $currentMinute,
        int $currentHour,
        int $currentDay,
        int $currentMonth,
        int $currentWeekday,
    ): bool {
        return $this->matchesPart($minuteExpr, $currentMinute, self::MINUTE_MIN, self::MINUTE_MAX)
            && $this->matchesPart($hourExpr, $currentHour, self::HOUR_MIN, self::HOUR_MAX)
            && $this->matchesPart($dayExpr, $currentDay, self::DAY_MIN, self::DAY_MAX)
            && $this->matchesPart($monthExpr, $currentMonth, self::MONTH_MIN, self::MONTH_MAX)
            && $this->matchesPart($weekdayExpr, $currentWeekday, self::WEEKDAY_MIN, self::WEEKDAY_MAX);
    }
}
