<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Auth\Session\BrowserTimeZone;
use Hilos\Core\Exception\ValidationException;

/** Captured calendar context; a cold window is distinct from an unbounded period. */
final readonly class AnalyticsPeriodFilter
{
    public const string timeZone = 'timeZone';
    public const string weekStartTs = 'weekStartTs';
    public const string dayStartTs = 'dayStartTs';
    public const string hourStartTs = 'hourStartTs';
    public const string period = 'period';

    public const string THIS_HOUR = 'this-hour';
    public const string TODAY = 'today';
    public const string THIS_WEEK = 'this-week';

    private const int MAX_SAFE_TS_MS = 9007199254740991;

    /**
     * @param ?string $timeZone Captured IANA zone, absent only in a cold window
     * @param ?int $weekStartTs Week start in UTC epoch milliseconds
     * @param ?int $dayStartTs Day start in UTC epoch milliseconds
     * @param ?int $hourStartTs Hour start in UTC epoch milliseconds
     * @param ?string $period Selected period, absent for any time
     */
    private function __construct(
        public ?string $timeZone,
        public ?int $weekStartTs,
        public ?int $dayStartTs,
        public ?int $hourStartTs,
        public ?string $period,
    ) {
    }

    /**
     * @param array<string, mixed> $filter Raw table filter map
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @return self Validated context, including the explicitly absent cold context
     * @throws ValidationException When context is partial, mistyped, unordered or beyond the server bound
     */
    public static function fromArray(array $filter, int $asOfTs): self
    {
        $keys = [self::timeZone, self::weekStartTs, self::dayStartTs, self::hourStartTs, self::period];
        if (array_intersect_key($filter, array_flip($keys)) === []) {
            $context = new self(null, null, null, null, null);
            $context->validateAsOf($asOfTs);
            return $context;
        }

        $timeZone = $filter[self::timeZone] ?? null;
        if (!is_string($timeZone) || !BrowserTimeZone::isValid($timeZone)) {
            throw new ValidationException('Choose a valid time zone for the analytics period.');
        }
        $weekStartTs = $filter[self::weekStartTs] ?? null;
        $dayStartTs = $filter[self::dayStartTs] ?? null;
        $hourStartTs = $filter[self::hourStartTs] ?? null;
        if (!is_int($weekStartTs) || !is_int($dayStartTs) || !is_int($hourStartTs)) {
            throw new ValidationException('Choose an analytics period with whole millisecond starts.');
        }
        if ($weekStartTs < 0 || $weekStartTs > $dayStartTs || $dayStartTs > $hourStartTs || $hourStartTs > self::MAX_SAFE_TS_MS) {
            throw new ValidationException('Choose an analytics period with ordered calendar starts.');
        }
        $period = $filter[self::period] ?? null;
        if (array_key_exists(self::period, $filter) && !in_array($period, [self::THIS_HOUR, self::TODAY, self::THIS_WEEK], true)) {
            throw new ValidationException('Choose this hour, today, this week or any time for the analytics period.');
        }

        $context = new self($timeZone, $weekStartTs, $dayStartTs, $hourStartTs, $period);
        $context->validateAsOf($asOfTs);
        return $context;
    }

    /** @return bool Whether the calendar context has arrived */
    public function hasContext(): bool
    {
        return $this->timeZone !== null;
    }

    /** @return ?int Selected lower bound in UTC epoch milliseconds, absent for any time or a cold window */
    public function startTs(): ?int
    {
        return match ($this->period) {
            self::THIS_HOUR => $this->hourStartTs,
            self::TODAY => $this->dayStartTs,
            self::THIS_WEEK => $this->weekStartTs,
            default => null,
        };
    }

    /**
     * @param int $asOfTs Server upper bound of the current read in UTC epoch milliseconds
     * @throws ValidationException When the bound is unsafe or precedes the captured calendar starts
     */
    public function validateAsOf(int $asOfTs): void
    {
        if ($asOfTs < 0 || $asOfTs > self::MAX_SAFE_TS_MS || ($this->hourStartTs !== null && $this->hourStartTs > $asOfTs)) {
            throw new ValidationException('Choose an analytics period that does not start after the server time.');
        }
    }
}
