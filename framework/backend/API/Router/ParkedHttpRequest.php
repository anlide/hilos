<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Core\Analytics\AnalyticsApiRequest;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Http\DTO\HttpRequestDTO;

/**
 * ParkedHttpRequest - what the router hands the connection instead of a response when an agent answers the address.
 *
 * The request itself travels to the agent; the rest stays in the master, because only the
 * connection needs it: the analytics description kept when the request was routed becomes one
 * journal record in {@see HttpClient::writeReply()}. None of it is on the wire.
 */
final readonly class ParkedHttpRequest
{
    /**
     * @param HttpRequestDTO $request Request handed to the agent
     * @param ?AnalyticsApiRequest $analytics Analytics request description, null when analytics is off
     * @param int $startedAtNs Moment the request was parked, from hrtime(true)
     */
    public function __construct(
        public HttpRequestDTO $request,
        public ?AnalyticsApiRequest $analytics,
        public int $startedAtNs,
    ) {
    }
}
