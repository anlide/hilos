<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Http\DTO\HttpRequestDTO;

/**
 * ParkedHttpRequest - what the router hands the connection instead of a response when an agent answers the address.
 *
 * The request itself travels to the agent; the rest stays in the master, because only the
 * connection needs it: the analytics row started when the request was routed is finished by
 * {@see HttpClient::writeReply()} with the reply's status and the time since this moment. None of
 * it is on the wire - the agent's process has no use for a row id of the master's.
 */
final readonly class ParkedHttpRequest
{
    /**
     * @param HttpRequestDTO $request Request handed to the agent
     * @param ?int $apiRequestId Analytics row started for the request, null when analytics is off
     * @param int $startedAtNs Moment the request was parked, from hrtime(true)
     */
    public function __construct(
        public HttpRequestDTO $request,
        public ?int $apiRequestId,
        public int $startedAtNs,
    ) {
    }
}
