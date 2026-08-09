<?php

declare(strict_types=1);

namespace Hilos\Push;

use Hilos\API\AsyncHttpClient;
use Hilos\Constants\HttpConstants;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Push\Exception\PushConfigException;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Encryption;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * WebPushRequestFactory - builds one signed, encrypted web-push request (HIL-199).
 *
 * The push channel's crypto seam: it turns a subscription plus a payload into a
 * {@see WebPushRequest} the non-blocking sender replays, reusing the framework dependency
 * {@see Encryption} (RFC 8291 `aes128gcm` payload encryption) and
 * {@see VAPID} (the signed application-server identity header). It mirrors the
 * library's own request assembly but stops at the request object rather than dispatching it, so the
 * send rides Hilos's non-blocking {@see AsyncHttpClient} instead of the library's blocking
 * PSR-18 client. Only the modern `aes128gcm` encoding is emitted (every current browser subscribes
 * with it). A single build failure never escapes as a raw SPL/library exception - it is wrapped as a
 * {@see PushConfigException} so the channel agent settles the send as a permanent failure.
 */
final class WebPushRequestFactory
{
    /** HTTP header naming the RFC 8188 content coding of the encrypted body. */
    private const string HEADER_CONTENT_ENCODING = 'Content-Encoding';

    /** HTTP header carrying the signed VAPID application-server identity. */
    private const string HEADER_AUTHORIZATION = 'Authorization';

    /** Web-push header naming how long the service retains an undelivered message. */
    private const string HEADER_TTL = 'TTL';

    /** MIME type of the opaque encrypted push body. */
    private const string CONTENT_TYPE_OCTET_STREAM = 'application/octet-stream';

    /**
     * Encrypts the payload for one subscription and assembles its signed web-push request.
     *
     * @param PushChannelConfig $config Effective VAPID config (validated here)
     * @param ObjectPushSubscription $subscription Target device subscription (endpoint and client keys)
     * @param string $payload Notification payload (a JSON document the service worker reads)
     * @return WebPushRequest The signed, encrypted request ready to replay
     * @throws PushConfigException When the VAPID config is invalid, the endpoint has no host, or encryption fails
     */
    public function build(PushChannelConfig $config, ObjectPushSubscription $subscription, string $payload): WebPushRequest
    {
        $vapid = $config->vapid();
        [$host, $port, $path, $useTls] = $this->parseEndpoint($subscription->endpoint);

        try {
            $padded = Encryption::padPayload($payload, Encryption::MAX_COMPATIBILITY_PAYLOAD_LENGTH, ContentEncoding::aes128gcm);
            $encrypted = Encryption::encrypt($padded, $subscription->p256dh, $subscription->auth, ContentEncoding::aes128gcm);
            $body = Encryption::getContentCodingHeader($encrypted['salt'], $encrypted['localPublicKey'], ContentEncoding::aes128gcm)
                . $encrypted['cipherText'];
            $vapidHeaders = VAPID::getVapidHeaders(
                ($useTls ? 'https' : 'http') . '://' . $host,
                $vapid['subject'],
                $vapid['publicKey'],
                $vapid['privateKey'],
                ContentEncoding::aes128gcm,
            );
        } catch (Throwable $e) {
            throw new PushConfigException('push payload encryption failed: ' . $e->getMessage(), previous: $e);
        }

        return new WebPushRequest($host, $port, $path, $useTls, [
            HttpConstants::HEADER_CONTENT_TYPE => self::CONTENT_TYPE_OCTET_STREAM,
            self::HEADER_CONTENT_ENCODING => ContentEncoding::aes128gcm->value,
            HttpConstants::HEADER_CONTENT_LENGTH => (string)strlen($body),
            self::HEADER_TTL => (string)$config->ttlSeconds,
            self::HEADER_AUTHORIZATION => $vapidHeaders[self::HEADER_AUTHORIZATION],
        ], $body);
    }

    /**
     * Parses a push endpoint URL into the target parts the async client needs.
     *
     * @param string $endpoint Browser push endpoint URL
     * @return array{0: string, 1: int, 2: string, 3: bool} Host, port, path-with-query, TLS flag
     * @throws PushConfigException When the endpoint URL has no host
     */
    private function parseEndpoint(string $endpoint): array
    {
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new PushConfigException('push endpoint has no host: ' . $endpoint);
        }

        $useTls = ($parts['scheme'] ?? 'https') !== 'http';
        // external-boundary: the neutral element of the request target — an endpoint may carry no query
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [$parts['host'], $parts['port'] ?? ($useTls ? 443 : 80), $path === '' ? '/' : $path, $useTls];
    }
}
