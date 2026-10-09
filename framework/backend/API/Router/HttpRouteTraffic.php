<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidFormatException;

/** Hourly, bounded request counts owned by the master's HTTP router. */
final class HttpRouteTraffic
{
    public const int SLOW_ANSWER_MS = 1000;
    public const int WINDOW_HOURS = 24;

    private const int LAST_SERVER_ERROR = 599;
    private const string ROUTE_KEY_SEPARATOR = "\0";
    private const string HOUR = 'hour';
    private const string REQUESTS = 'requests';
    private const string SERVER_ERRORS = 'serverErrors';
    private const string SLOW = 'slow';
    private const string SLOWEST_MS = 'slowestMs';

    /** @var array<string, array<int, array{hour: int, requests: int, serverErrors: int, slow: int, slowestMs: int}>> */
    private array $routes = [];

    /** @var array<int, array{hour: int, requests: int, serverErrors: int, slow: int, slowestMs: int}> */
    private array $unrouted = [];

    private int $revision = 0;

    /**
     * @param int $countingSince Unix second from which this router has counted
     */
    public function __construct(private readonly int $countingSince)
    {
    }

    /** @return int Unix second when counting began */
    public function countingSince(): int
    {
        return $this->countingSince;
    }

    /** @return int Number of recorded outcomes, used to avoid unnecessary frame builds */
    public function revision(): int
    {
        return $this->revision;
    }

    /**
     * The caller supplies a registered method and path, never the raw request address.
     *
     * @param string $method Registered HTTP method
     * @param string $path Registered path, including any template placeholders
     * @param ?int $status Answer status, null when the browser left before an answer
     * @param int $durationMs Duration of the handler or parked request
     * @param int $now Unix second of the outcome
     * @throws InvalidFormatException When the duration is negative
     */
    public function record(string $method, string $path, ?int $status, int $durationMs, int $now): void
    {
        $key = $method . self::ROUTE_KEY_SEPARATOR . $path;
        $this->routes[$key] ??= [];
        $this->recordIn($this->routes[$key], $status, $durationMs, $now);
    }

    /**
     * @param int $status Answer status before a route was chosen
     * @param int $durationMs Duration in milliseconds
     * @param int $now Unix second of the outcome
     * @throws InvalidFormatException When the duration is negative
     */
    public function recordUnrouted(int $status, int $durationMs, int $now): void
    {
        $this->recordIn($this->unrouted, $status, $durationMs, $now);
    }

    /**
     * @param string $method Registered HTTP method
     * @param string $path Registered path
     * @param int $now Unix second selecting the current window
     * @return HttpRouteTally Counts within the current hour and 23 preceding hours
     * @throws InvalidFormatException When a recorded tally is inconsistent
     */
    public function tally(string $method, string $path, int $now): HttpRouteTally
    {
        $key = $method . self::ROUTE_KEY_SEPARATOR . $path;

        return $this->sum($this->routes[$key] ?? [], $now);
    }

    /**
     * @param int $now Unix second selecting the current window
     * @return HttpRouteTally Counts for requests without a route
     * @throws InvalidFormatException When a recorded tally is inconsistent
     */
    public function unroutedTally(int $now): HttpRouteTally
    {
        return $this->sum($this->unrouted, $now);
    }

    /**
     * @param array<int, array{hour: int, requests: int, serverErrors: int, slow: int, slowestMs: int}> $buckets Hour ring to update
     * @param ?int $status Response status, or null for an abandoned request
     * @param int $durationMs Whole elapsed milliseconds
     * @param int $now Unix second of the outcome
     * @throws InvalidFormatException When the duration is negative
     */
    private function recordIn(array &$buckets, ?int $status, int $durationMs, int $now): void
    {
        if ($durationMs < 0) {
            throw new InvalidFormatException('HTTP request duration cannot be negative');
        }

        $hour = intdiv($now, TimeConstants::SECONDS_PER_HOUR);
        $slot = $hour % self::WINDOW_HOURS;
        if (!isset($buckets[$slot]) || $buckets[$slot][self::HOUR] !== $hour) {
            $buckets[$slot] = [
                self::HOUR => $hour,
                self::REQUESTS => 0,
                self::SERVER_ERRORS => 0,
                self::SLOW => 0,
                self::SLOWEST_MS => 0,
            ];
        }

        $buckets[$slot][self::REQUESTS]++;
        if ($status !== null && $status >= HttpConstants::HTTP_INTERNAL_ERROR && $status <= self::LAST_SERVER_ERROR) {
            $buckets[$slot][self::SERVER_ERRORS]++;
        }
        if ($durationMs > self::SLOW_ANSWER_MS) {
            $buckets[$slot][self::SLOW]++;
        }
        $buckets[$slot][self::SLOWEST_MS] = max($buckets[$slot][self::SLOWEST_MS], $durationMs);
        $this->revision++;
    }

    /**
     * @param array<int, array{hour: int, requests: int, serverErrors: int, slow: int, slowestMs: int}> $buckets Hour ring to read
     * @param int $now Unix second selecting the current window
     * @return HttpRouteTally Window sum and maximum duration
     * @throws InvalidFormatException When a recorded tally is inconsistent
     */
    private function sum(array $buckets, int $now): HttpRouteTally
    {
        $currentHour = intdiv($now, TimeConstants::SECONDS_PER_HOUR);
        $requests = 0;
        $serverErrors = 0;
        $slow = 0;
        $slowestMs = null;
        foreach ($buckets as $bucket) {
            if ($bucket[self::HOUR] > $currentHour || $bucket[self::HOUR] <= $currentHour - self::WINDOW_HOURS) {
                continue;
            }
            $requests += $bucket[self::REQUESTS];
            $serverErrors += $bucket[self::SERVER_ERRORS];
            $slow += $bucket[self::SLOW];
            $slowestMs = max($slowestMs ?? 0, $bucket[self::SLOWEST_MS]);
        }

        return new HttpRouteTally($requests, $serverErrors, $slow, $slowestMs);
    }
}
