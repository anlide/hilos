<?php

declare(strict_types=1);

namespace Hilos\Sms;

use Hilos\API\AsyncHttpClient;
use Hilos\Auth\OAuth\OAuthHttpRequest;

/**
 * SmsHttpRequest - a gateway HTTP request the SMS agent replays through
 * {@see AsyncHttpClient} (HIL-285).
 *
 * The seam between a provider (which knows the endpoint URL, credentials, and field map)
 * and the tick-driven agent (which owns the non-blocking sockets): a provider returns
 * this immutable descriptor and the agent constructs the client from `host`/`port`/`useTls`
 * and applies `method`/`path`/`headers`/`body`. Modelled on
 * {@see OAuthHttpRequest}, itself shaped after the client's own inputs -
 * constructor `(host, port, path, useTls)` plus `setRequestOptions(method, path, body, headers)` -
 * so no reshaping is needed at the call site.
 */
final readonly class SmsHttpRequest
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
