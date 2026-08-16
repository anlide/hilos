<?php

declare(strict_types=1);

namespace Hilos\API\DTO;

use Hilos\API\AsyncHttpClient;
use Hilos\Auth\OAuth\OAuthHttpRequest;
use Hilos\Sms\SmsHttpRequest;

/**
 * An outbound HTTP request a tick-driven agent replays through {@see AsyncHttpClient}.
 *
 * The generic half of the seam {@see AsyncHttpResponse} already holds the other end
 * of: something that knows endpoints, credentials and field maps (a provider, a code
 * channel) returns this immutable descriptor, and the agent that owns the non-blocking
 * sockets constructs the client from `host`/`port`/`useTls` and applies
 * `method`/`path`/`headers`/`body`. Shaped after the client's own inputs -
 * constructor `(host, port, path, useTls)` plus
 * `setRequestOptions(method, path, body, headers)` - so nothing is reshaped at the
 * call site.
 *
 * It carries no credentials of its own: whatever authenticates the call is already
 * baked into `headers` or the query by whoever built it, which is what keeps the
 * agent free of every provider's auth convention.
 *
 * Subsystems that predate this one keep their own identical descriptors
 * ({@see OAuthHttpRequest}, {@see SmsHttpRequest}); nothing is gained by rewriting
 * them, and everything new speaks this one so a fourth copy never appears.
 */
final readonly class AsyncHttpRequest
{
    /**
     * @param string $host Target host
     * @param int $port Target port
     * @param bool $useTls Whether to connect over TLS
     * @param string $method HTTP method (see HttpConstants)
     * @param string $path Request path including any query string
     * @param array<string, string> $headers Request headers
     * @param ?string $body Request body, or null for a bodyless request
     */
    public function __construct(
        public string $host,
        public int $port,
        public bool $useTls,
        public string $method,
        public string $path,
        public array $headers,
        public ?string $body,
    ) {
    }
}
