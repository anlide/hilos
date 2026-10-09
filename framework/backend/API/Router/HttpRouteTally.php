<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Core\Exception\InvalidFormatException;

/** Counts and longest duration of completed requests in a route's current window. */
final readonly class HttpRouteTally
{
    /**
     * @param int $requests Requests, including a browser that left before an agent answered
     * @param int $serverErrors Answers with a 5xx status
     * @param int $slow Requests lasting more than the slow-answer threshold
     * @param ?int $slowestMs Longest duration, null only when there are no requests
     * @throws InvalidFormatException When counts or duration disagree
     */
    public function __construct(
        public int $requests,
        public int $serverErrors,
        public int $slow,
        public ?int $slowestMs,
    ) {
        if ($requests < 0 || $serverErrors < 0 || $serverErrors > $requests
            || $slow < 0 || $slow > $requests || ($slowestMs === null) !== ($requests === 0)
            || ($slowestMs !== null && $slowestMs < 0)) {
            throw new InvalidFormatException('Invalid HTTP route tally');
        }
    }
}
