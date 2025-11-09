<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Cron;

/**
 * CronRule - Represents a single cron rule with cron expression
 *
 * Stores cron job configuration: name, cron expression, and last execution time.
 * Supports standard cron expressions: "minute hour day month weekday"
 * Example: "*\/5 * * * *" means every 5 minutes
 */
class CronRule
{
    /** @var string Cron job name (unique identifier) */
    public readonly string $name;

    /** @var string Cron expression (minute hour day month weekday) */
    public string $expression;

    /** @var float Last execution timestamp */
    public float $lastRun;

    /**
     * Constructor
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
        $this->lastRun = floor(time() / 60);
    }

    /**
     * Check if cron job should run now
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
        $currentMinuteTimestamp = floor($currentTime / 60);

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
        if (count($parts) !== 5) {
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
     * Parse cron expression into parts
     *
     * @return array<string> Array of 5 parts: [minute, hour, day, month, weekday]
     */
    private function parseExpression(): array
    {
        $parts = explode(' ', trim($this->expression));
        if (count($parts) !== 5) {
            return [];
        }
        return $parts;
    }

    /**
     * Check if time matches cron expression part
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
        if ($part === '*') {
            return true;
        }

        // Check for step value: */N or N-N/N
        $slashPos = strpos($part, '/');
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

            if ($range === '*') {
                // */N - every N values starting from min
                // Safe: value is already validated to be >= min, so (value - min) >= 0
                return (($value - $min) % $step) === 0;
            }

            // N-N/N - range with step
            $dashPos = strpos($range, '-');
            if ($dashPos !== false) {
                $rangeMinStr = substr($range, 0, $dashPos);
                $rangeMaxStr = substr($range, $dashPos + 1);

                if ($rangeMinStr === '' || $rangeMaxStr === '' ||
                    !ctype_digit($rangeMinStr) || !ctype_digit($rangeMaxStr)) {
                    return false;
                }

                $rangeMin = (int)$rangeMinStr;
                $rangeMax = (int)$rangeMaxStr;

                // Validate range
                if ($rangeMin < $min || $rangeMax > $max || $rangeMin > $rangeMax) {
                    return false;
                }

                // Check if value is within range
                if ($value >= $rangeMin && $value <= $rangeMax) {
                    // Safe: value >= rangeMin, so (value - rangeMin) >= 0
                    return (($value - $rangeMin) % $step) === 0;
                }
                return false;
            }

            // Invalid format: something/N without * or range
            return false;
        }

        // Check for range: N-M (before list check, as ranges are more common)
        $dashPos = strpos($part, '-');
        if ($dashPos !== false) {
            $rangeMinStr = substr($part, 0, $dashPos);
            $rangeMaxStr = substr($part, $dashPos + 1);

            if ($rangeMinStr === '' || $rangeMaxStr === '' ||
                !ctype_digit($rangeMinStr) || !ctype_digit($rangeMaxStr)) {
                return false;
            }

            $rangeMin = (int)$rangeMinStr;
            $rangeMax = (int)$rangeMaxStr;

            // Validate range
            if ($rangeMin < $min || $rangeMax > $max || $rangeMin > $rangeMax) {
                return false;
            }

            return $value >= $rangeMin && $value <= $rangeMax;
        }

        // Check for list: N,M,K (less common, check after ranges)
        if (str_contains($part, ',')) {
            $values = explode(',', $part);
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
     * Check if current time matches cron expression
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
        return $this->matchesPart($minuteExpr, $currentMinute, 0, 59)
            && $this->matchesPart($hourExpr, $currentHour, 0, 23)
            && $this->matchesPart($dayExpr, $currentDay, 1, 31)
            && $this->matchesPart($monthExpr, $currentMonth, 1, 12)
            && $this->matchesPart($weekdayExpr, $currentWeekday, 0, 6);
    }
}
