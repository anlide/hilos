<?php

declare(strict_types=1);

namespace Hilos\Push;

use Hilos\API\AsyncHttpClient;
use Hilos\Push\Delivery\PushEndpointSend;

/**
 * WebPushRequest - one encrypted web-push HTTP request ready to replay (HIL-199).
 *
 * The transport-agnostic value {@see WebPushRequestFactory} produces from a subscription and an
 * encrypted payload: the parsed target ({@see $host}/{@see $port}/{@see $path}/{@see $useTls}) plus
 * the signed VAPID headers and the binary encrypted body. {@see PushEndpointSend}
 * replays it on a non-blocking {@see AsyncHttpClient}, keeping the web-push crypto (which
 * needs no socket) separate from the send (which must not block the worker loop). Immutable; the
 * factory builds one per endpoint per notification.
 */
final class WebPushRequest
{
    /**
     * @param string $host Push service host
     * @param int $port Push service port (443 for TLS, 80 otherwise)
     * @param string $path Request path including any query string
     * @param bool $useTls Whether the endpoint is https
     * @param array<string, string> $headers Request headers (VAPID authorization, content coding, TTL)
     * @param string $body Binary encrypted request body (content-coding header plus ciphertext)
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $path,
        public readonly bool $useTls,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
