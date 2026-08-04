<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\API\AsyncHttpClient;

/**
 * A provider HTTP request the OAuth async agent replays through
 * {@see AsyncHttpClient} (HIL-281).
 *
 * The seam between a provider (which knows the endpoint URLs, credentials, and
 * field map) and the tick-driven agent (which owns the non-blocking sockets):
 * a provider returns this immutable descriptor and the agent constructs the
 * client from `host`/`port`/`useTls` and applies `method`/`path`/`headers`/`body`.
 * Modelled on the client's own inputs — constructor `(host, port, path, useTls)`
 * plus `setRequestOptions(method, path, body, headers)` — so no reshaping is
 * needed at the call site.
 */
final readonly class OAuthHttpRequest
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
