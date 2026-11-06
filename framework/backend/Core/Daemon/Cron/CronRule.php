<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Cron;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\Cron\Exception\CronException;
use Hilos\Core\Daemon\Cron\Exception\CronRuleNameException;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Represents a single cron rule with cron expression.
 *
 * Stores cron job configuration: name, cron expression, and last execution time.
 * Supports standard cron expressions: "minute hour day month weekday".
 * Example: "*\/5 * * * *" means every 5 minutes.
 *
 * Every expression is read on the project clock, HILOS_PROJECT_TIME_ZONE, and never on the
 * process zone, which is UTC: "0 3 * * *" in Europe/Warsaw fires at 02:00Z in winter and at
 * 01:00Z in summer. The zone is read per calculation and kept nowhere.
 *
 * A test command may arm a rule once with {@see fireOnNextCheck()}: the next check fires it
 * whatever the expression says, and the schedule stays as it was.
 */
class CronRule
{
    /** Prefix reserved for framework cron rule names. A project rule must not use it. */
    public const string FRAMEWORK_NAME_PREFIX = 'hilos_';

    /** Number of whitespace-separated fields in a cron expression. */
    private const int FIELD_COUNT = 5;

    /** Seconds per minute, used to derive minute-level run timestamps. */
    private const int SECONDS_PER_MINUTE = 60;

    /** Window around the search start for a possible repeated local hour. */
    private const int SECONDS_PER_DAY = 86400;

    /** Long enough to include a leap day on every weekday. */
    private const int NEXT_RUN_HORIZON_YEARS = 28;

    /** Use noon to determine a calendar day without crossing a midnight clock change. */
    private const int MIDDAY_HOUR = 12;

    /** Date format of a local calendar day, the key the clock changes of a search are filed under. */
    private const string DAY_KEY_FORMAT = 'Ymd';

    /** Date format of the weekday, numbered as in the weekday field: 0 = Sunday. */
    private const string WEEKDAY_FORMAT = 'w';

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

    /** Unix time of the last matching run, distinct from the seeded minute in $lastRun. */
    public ?int $lastFiredAt = null;

    /** Armed by {@see fireOnNextCheck()} and taken by the next {@see shouldRun()}. */
    private bool $fireOnNextCheck = false;

    /** @var array<string, true> Test-only pause ids, owned and lost with this rule instance */
    private array $schedulePauses = [];

    /** Last absolute minute whose expression did not match; private to this rule instance. */
    private ?float $lastUnmatchedMinute = null;

    /** Expression used by the last negative calculation. */
    private ?string $lastUnmatchedExpression = null;

    /** Accessor identity of that calculation, so replacing the context invalidates it. */
    private ?EnvAccessor $lastUnmatchedEnv = null;

    /**
     * Creates a cron rule after checking the name against its origin.
     *
     * The origin is a validation parameter and is not kept: a project name may not start with
     * the reserved prefix, and a framework name must. A refused name does not produce a rule.
     *
     * @param string $name Cron job name (unique identifier)
     * @param string $expression Cron expression (minute hour day month weekday)
     * @param CronRuleOrigin $origin Declared origin of the name, project when omitted
     * @throws CronRuleNameException When a project name uses the reserved prefix or a framework name lacks it
     */
    public function __construct(
        string $name,
        string $expression,
        CronRuleOrigin $origin = CronRuleOrigin::PROJECT,
    ) {
        self::assertNameOrigin($name, $origin);
        $this->name = $name;
        $this->expression = $expression;

        // Store creation time as initial reference point (prevents immediate execution)
        // Use minute-level timestamp for consistent comparison
        $this->lastRun = floor(time() / self::SECONDS_PER_MINUTE);
    }

    /**
     * Refuses a name that does not match the origin it was declared with.
     *
     * The prefix compare is case-sensitive and does not rewrite the name. Nothing else about
     * the name's shape is decided here.
     *
     * @param string $name Cron job name
     * @param CronRuleOrigin $origin Declared origin of the name
     * @throws CronRuleNameException When a project name uses the reserved prefix or a framework name lacks it
     */
    public static function assertNameOrigin(string $name, CronRuleOrigin $origin): void
    {
        $reserved = str_starts_with($name, self::FRAMEWORK_NAME_PREFIX);
        if ($origin === CronRuleOrigin::PROJECT && $reserved) {
            throw new CronRuleNameException(
                'Cron rule name "' . $name . '" starts with the reserved prefix "'
                . self::FRAMEWORK_NAME_PREFIX . '"; framework origin is required',
            );
        }
        if ($origin === CronRuleOrigin::FRAMEWORK && !$reserved) {
            throw new CronRuleNameException(
                'Cron rule name "' . $name . '" does not start with the reserved prefix "'
                . self::FRAMEWORK_NAME_PREFIX . '"; project origin is required',
            );
        }
    }

    /**
     * Whether an expression is a schedule this class can run.
     *
     * The public companion of the private parsing below, so that whoever accepts a schedule for
     * storage — a settings write, a config check — asks the same class that later runs it. An
     * expression that passes here is understood field by field by {@see shouldRun()}; without this
     * companion a caller counts fields instead, and "0 3 * * abc" passes the count and then never
     * fires.
     *
     * @param string $expression Cron expression to check (minute hour day month weekday)
     * @return bool True when the expression carries five fields and every field is well-formed
     */
    public static function isValidExpression(string $expression): bool
    {
        $parts = self::parseExpression($expression);
        if ($parts === []) {
            return false;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        return self::isValidPart($minute, self::MINUTE_MIN, self::MINUTE_MAX)
            && self::isValidPart($hour, self::HOUR_MIN, self::HOUR_MAX)
            && self::isValidPart($day, self::DAY_MIN, self::DAY_MAX)
            && self::isValidPart($month, self::MONTH_MIN, self::MONTH_MAX)
            && self::isValidPart($weekday, self::WEEKDAY_MIN, self::WEEKDAY_MAX);
    }

    /**
     * Find the first matching local minute strictly after the given instant.
     * The day of month and weekday must both match, as in shouldRun().
     *
     * The calendar is the project's: the search starts, ends, counts month lengths and
     * weekdays and meets its clock changes in HILOS_PROJECT_TIME_ZONE, while every candidate
     * stays an absolute instant compared with $after.
     *
     * @param string $expression Cron expression to search
     * @param int $after Unix time before the next run
     * @return int|null Start of the matching minute, or null when none exists within 28 years
     * @throws EnvException When the project time zone cannot be read
     */
    public static function nextRunAfter(string $expression, int $after): ?int
    {
        if (!self::isValidExpression($expression)) {
            return null;
        }

        [$minuteExpr, $hourExpr, $dayExpr, $monthExpr, $weekdayExpr] = self::parseExpression($expression);
        $zone = new DateTimeZone(Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string());
        $start = self::localTime($after, $zone);
        [, , $startDay, $startMonth] = self::calendarFields($start);
        $startYear = (int)$start->format('Y');
        $lastYear = $startYear + self::NEXT_RUN_HORIZON_YEARS;
        $deadline = $start->setDate($lastYear, $startMonth, $startDay)->getTimestamp();
        $foldsByDay = [];
        $transitions = $zone->getTransitions($after - self::SECONDS_PER_DAY, $deadline);
        if ($transitions !== false) {
            $previousOffset = $transitions[0]['offset'];
            foreach (array_slice($transitions, 1) as $transition) {
                $foldSeconds = $previousOffset - $transition['offset'];
                $previousOffset = $transition['offset'];
                if ($foldSeconds <= 0) {
                    continue;
                }

                $fold = [$transition['ts'], $foldSeconds];
                $foldsByDay[self::localTime($transition['ts'] - 1, $zone)->format(self::DAY_KEY_FORMAT)][] = $fold;
                $foldsByDay[self::localTime($transition['ts'], $zone)->format(self::DAY_KEY_FORMAT)][] = $fold;
            }
        }

        $calendar = $start->setTime(self::MIDDAY_HOUR, 0);
        for ($year = $startYear; $year <= $lastYear; $year++) {
            for ($month = self::MONTH_MIN; $month <= self::MONTH_MAX; $month++) {
                if ($year === $startYear && $month < $startMonth) {
                    continue;
                }
                if (!self::matchesPart($monthExpr, $month, self::MONTH_MIN, self::MONTH_MAX)) {
                    continue;
                }

                $daysInMonth = (int)$calendar->setDate($year, $month, self::DAY_MIN)->format('t');
                for ($day = self::DAY_MIN; $day <= $daysInMonth; $day++) {
                    if ($year === $startYear && $month === $startMonth && $day < $startDay) {
                        continue;
                    }
                    if (!self::matchesPart($dayExpr, $day, self::DAY_MIN, self::DAY_MAX)) {
                        continue;
                    }

                    $noon = $calendar->setDate($year, $month, $day);
                    $weekday = (int)$noon->format(self::WEEKDAY_FORMAT);
                    if (!self::matchesPart($weekdayExpr, $weekday, self::WEEKDAY_MIN, self::WEEKDAY_MAX)) {
                        continue;
                    }

                    $earliest = null;
                    $folds = $foldsByDay[$noon->format(self::DAY_KEY_FORMAT)] ?? [];
                    for ($hour = self::HOUR_MIN; $hour <= self::HOUR_MAX; $hour++) {
                        if (!self::matchesPart($hourExpr, $hour, self::HOUR_MIN, self::HOUR_MAX)) {
                            continue;
                        }

                        for ($minute = self::MINUTE_MIN; $minute <= self::MINUTE_MAX; $minute++) {
                            if (!self::matchesPart($minuteExpr, $minute, self::MINUTE_MIN, self::MINUTE_MAX)) {
                                continue;
                            }

                            $candidates = [$noon->setTime($hour, $minute)->getTimestamp()];
                            foreach ($folds as [$transitionAt, $foldSeconds]) {
                                if ($candidates[0] >= $transitionAt - $foldSeconds && $candidates[0] < $transitionAt) {
                                    $candidates[] = $candidates[0] + $foldSeconds;
                                } elseif ($candidates[0] >= $transitionAt && $candidates[0] < $transitionAt + $foldSeconds) {
                                    $candidates[] = $candidates[0] - $foldSeconds;
                                }
                            }

                            foreach ($candidates as $candidate) {
                                if ($candidate <= $after || $candidate > $deadline) {
                                    continue;
                                }

                                [$localMinute, $localHour, $localDay, $localMonth, $localWeekday] = self::calendarFields(
                                    self::localTime($candidate, $zone),
                                );
                                if ($localHour !== $hour || $localMinute !== $minute ||
                                    $localDay !== $day || $localMonth !== $month) {
                                    continue;
                                }

                                if (self::matchesTime(
                                    $minuteExpr,
                                    $hourExpr,
                                    $dayExpr,
                                    $monthExpr,
                                    $weekdayExpr,
                                    $localMinute,
                                    $localHour,
                                    $localDay,
                                    $localMonth,
                                    $localWeekday,
                                ) && ($earliest === null || $candidate < $earliest)) {
                                    $earliest = $candidate;
                                }
                            }
                        }
                    }

                    if ($earliest !== null) {
                        return $earliest;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if cron job should run now.
     *
     * Reuses a negative check within the same absolute minute while the expression and env
     * context stay the same. Failed calculations are retried, and run history stays separate.
     *
     * The minute guard is absolute and the five fields are read on the project clock, so the
     * repeated autumn hour fires once per absolute minute and the skipped spring hour not at all.
     *
     * A rule armed by {@see fireOnNextCheck()} fires at this check before either test, once, and
     * its run is marked as a scheduled one is, so the schedule does not fire again that minute.
     *
     * Schedule pauses suppress unarmed checks before reading time, leaving run history and the
     * negative cache untouched. An armed check takes precedence and leaves the pauses in place.
     *
     * @return bool True if should run
     * @throws EnvException When the project time zone cannot be read
     */
    public function shouldRun(): bool
    {
        if ($this->fireOnNextCheck) {
            $this->fireOnNextCheck = false;
            $this->lastUnmatchedMinute = null;
            $now = $this->currentTimestamp();
            $this->lastRun = floor($now / self::SECONDS_PER_MINUTE);
            $this->lastFiredAt = $now;
            return true;
        }

        if ($this->schedulePauses !== []) {
            return false;
        }

        $currentTime = $this->currentTimestamp();

        // Don't run if already executed in current minute
        // Compare minute-level timestamps (lastRun is already stored at minute level)
        $currentMinuteTimestamp = floor($currentTime / self::SECONDS_PER_MINUTE);

        if ($this->lastRun === $currentMinuteTimestamp) {
            return false;
        }

        if ($this->lastUnmatchedMinute === $currentMinuteTimestamp
            && $this->lastUnmatchedExpression === $this->expression
            && $this->lastUnmatchedEnv === Hilos::$env) {
            return false;
        }

        $this->lastUnmatchedMinute = null;
        if ($this->matchesAt($currentTime)) {
            $this->lastRun = $currentMinuteTimestamp;
            $this->lastFiredAt = $currentTime;
            return true;
        }

        $this->lastUnmatchedMinute = $currentMinuteTimestamp;
        $this->lastUnmatchedExpression = $this->expression;
        $this->lastUnmatchedEnv = Hilos::$env;
        return false;
    }

    /**
     * Arm the rule so that the next {@see shouldRun()} fires it, once.
     *
     * Nothing about the schedule changes: the expression stays, and so does the next scheduled
     * run. The armed check marks its run as a scheduled one does, the minute in $lastRun and the
     * moment in $lastFiredAt.
     *
     * @internal Called only on the test:cron:fire path, never by application code.
     */
    public function fireOnNextCheck(): void
    {
        $this->fireOnNextCheck = true;
    }

    /**
     * Whether the arm of {@see fireOnNextCheck()} still waits for its check.
     *
     * The armed check is the owner's own {@see shouldRun()}, so whoever armed the rule learns
     * that it fired from this turning false, not from the moment in $lastFiredAt, which a run
     * earlier in the same second leaves unchanged.
     *
     * @internal Read only on the test:cron:fire path, never by application code.
     * @return bool True from the arming until the next check takes it
     */
    public function isArmedForNextCheck(): bool
    {
        return $this->fireOnNextCheck;
    }

    /**
     * Suppress scheduled checks until this key and every other pause key have been removed.
     * Adding the same key again has no additional effect; an armed check still fires once.
     *
     * @internal Test-only schedule control, never application configuration.
     * @param string $pauseId Independent pause key supplied by the caller
     */
    public function pauseSchedule(string $pauseId): void
    {
        $this->schedulePauses[$pauseId] = true;
    }

    /**
     * Remove only this pause key without arming or running the rule. The next ordinary check
     * follows the schedule if no pause keys remain.
     *
     * @internal Test-only schedule control, never application configuration.
     * @param string $pauseId Pause key to remove
     * @throws CronException When this rule has no pause with the supplied key
     */
    public function resumeSchedule(string $pauseId): void
    {
        if (!isset($this->schedulePauses[$pauseId])) {
            throw new CronException("Cron rule {$this->name} has no schedule pause {$pauseId}.");
        }

        unset($this->schedulePauses[$pauseId]);
    }

    /**
     * Read the current Unix time for a check that is not suppressed.
     *
     * The seam a test overrides to place the check at a fixed moment.
     *
     * @return int Current Unix time in seconds
     */
    protected function currentTimestamp(): int
    {
        return time();
    }

    /**
     * Match the expression on the current project clock without changing run history.
     *
     * @param int $timestamp Unix time of the check
     * @return bool Whether all five calendar fields match
     * @throws EnvException When the project time zone cannot be read
     */
    protected function matchesAt(int $timestamp): bool
    {
        [$currentMinute, $currentHour, $currentDay, $currentMonth, $currentWeekday] = self::calendarFields(
            self::localTime($timestamp, new DateTimeZone(Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string())),
        );

        $parts = self::parseExpression($this->expression);
        if (count($parts) !== self::FIELD_COUNT) {
            return false;
        }

        return self::matchesTime(
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
    }

    /**
     * Place an instant on the project clock.
     *
     * @param int $timestamp Unix time
     * @param DateTimeZone $zone Project time zone of this calculation
     * @return DateTimeImmutable The same instant in the project time zone
     */
    private static function localTime(int $timestamp, DateTimeZone $zone): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $timestamp)->setTimezone($zone);
    }

    /**
     * Read the five calendar fields of an instant already on the project clock.
     *
     * @param DateTimeImmutable $local Instant in the project time zone
     * @return list<int> Minute, hour, day of month, month and weekday, in expression order
     */
    private static function calendarFields(DateTimeImmutable $local): array
    {
        return [
            (int)$local->format('i'),
            (int)$local->format('G'),
            (int)$local->format('j'),
            (int)$local->format('n'),
            (int)$local->format(self::WEEKDAY_FORMAT),
        ];
    }

    /**
     * Parse cron expression into parts.
     *
     * @param string $expression Cron expression (minute hour day month weekday)
     * @return list<string> Five parts: minute, hour, day, month, weekday; empty when the field count is wrong
     */
    private static function parseExpression(string $expression): array
    {
        $parts = explode(self::FIELD_SEPARATOR, trim($expression));
        if (count($parts) !== self::FIELD_COUNT) {
            return [];
        }
        return $parts;
    }

    /**
     * Check whether a single field is well-formed for its value bounds.
     *
     * The syntax half of {@see matchesPart()}: same token shapes (wildcard, step, range, list,
     * single value), asked without a current value to compare against.
     *
     * @param string $part Cron expression part (e.g., "*\/5", "*", "15", "1-5")
     * @param int $min Minimum value for this field
     * @param int $max Maximum value for this field
     * @return bool True when the part is a token this class understands
     */
    private static function isValidPart(string $part, int $min, int $max): bool
    {
        $part = trim($part);
        if ($part === '') {
            return false;
        }
        if ($part === self::WILDCARD) {
            return true;
        }

        // Step value: */N or N-M/N.
        $slashPos = strpos($part, self::STEP_SEPARATOR);
        if ($slashPos !== false) {
            $range = substr($part, 0, $slashPos);
            $stepStr = substr($part, $slashPos + 1);

            if ($stepStr === '' || !ctype_digit($stepStr) || (int)$stepStr <= 0) {
                return false;
            }

            return $range === self::WILDCARD || self::parseRange($range, $min, $max) !== null;
        }

        // Range: N-M.
        if (str_contains($part, self::RANGE_SEPARATOR)) {
            return self::parseRange($part, $min, $max) !== null;
        }

        // List: N,M,K - every member has to be a value of this field.
        if (str_contains($part, self::LIST_SEPARATOR)) {
            foreach (explode(self::LIST_SEPARATOR, $part) as $value) {
                if (!self::isValidValue(trim($value), $min, $max)) {
                    return false;
                }
            }

            return true;
        }

        return self::isValidValue($part, $min, $max);
    }

    /**
     * Check whether a token is a plain number inside the field bounds.
     *
     * @param string $token Single cron token
     * @param int $min Minimum value for this field
     * @param int $max Maximum value for this field
     * @return bool True when the token is digits inside the bounds
     */
    private static function isValidValue(string $token, int $min, int $max): bool
    {
        if ($token === '' || !ctype_digit($token)) {
            return false;
        }

        $value = (int)$token;

        return $value >= $min && $value <= $max;
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
    private static function matchesPart(string $part, int $value, int $min, int $max): bool
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
            $bounds = self::parseRange($range, $min, $max);
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
            $bounds = self::parseRange($part, $min, $max);
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
    private static function parseRange(string $range, int $min, int $max): ?array
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
    private static function matchesTime(
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
        return self::matchesPart($minuteExpr, $currentMinute, self::MINUTE_MIN, self::MINUTE_MAX)
            && self::matchesPart($hourExpr, $currentHour, self::HOUR_MIN, self::HOUR_MAX)
            && self::matchesPart($dayExpr, $currentDay, self::DAY_MIN, self::DAY_MAX)
            && self::matchesPart($monthExpr, $currentMonth, self::MONTH_MIN, self::MONTH_MAX)
            && self::matchesPart($weekdayExpr, $currentWeekday, self::WEEKDAY_MIN, self::WEEKDAY_MAX);
    }
}
