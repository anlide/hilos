<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Push;

use Hilos\Constants\HttpConstants;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Push\Exception\PushConfigException;
use Hilos\Push\PushChannelConfig;
use Hilos\Push\WebPushRequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests the crypto seam that turns a subscription into a replayable web-push request (HIL-199).
 *
 * The factory validates the VAPID config, parses the endpoint into the parts the async
 * client needs, and encrypts the payload (RFC 8291 aes128gcm) into a signed request - but
 * stops at the request object so the send can ride the non-blocking client rather than the
 * library's blocking one. A missing config or a hostless endpoint is wrapped as a
 * {@see PushConfigException} so the agent settles the send as a permanent failure.
 */
final class WebPushRequestFactoryTest extends TestCase
{
    private WebPushRequestFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new WebPushRequestFactory();
    }

    public function testUnconfiguredConfigIsRejected(): void
    {
        $this->expectException(PushConfigException::class);
        $this->factory->build(new PushChannelConfig('', '', ''), $this->subscription('https://push.example/a'), '{}');
    }

    public function testEndpointWithoutHostIsRejected(): void
    {
        $this->expectException(PushConfigException::class);
        $this->expectExceptionMessage('push endpoint has no host');
        $this->factory->build($this->config(), $this->subscription('/no-scheme-no-host'), '{}');
    }

    public function testBuildsASignedEncryptedTlsRequest(): void
    {
        $request = $this->factory->build(
            $this->config(),
            $this->subscription('https://push.example.com/wpush/abc?token=1'),
            '{"title":"Hi"}',
        );

        self::assertSame('push.example.com', $request->host);
        self::assertSame(443, $request->port);
        self::assertSame('/wpush/abc?token=1', $request->path);
        self::assertTrue($request->useTls);

        self::assertArrayHasKey('Authorization', $request->headers);
        self::assertStringStartsWith('vapid ', $request->headers['Authorization']);
        self::assertSame('aes128gcm', $request->headers['Content-Encoding']);
        self::assertSame('application/octet-stream', $request->headers[HttpConstants::HEADER_CONTENT_TYPE]);
        self::assertSame(
            (string)strlen($request->body),
            $request->headers[HttpConstants::HEADER_CONTENT_LENGTH],
        );
        self::assertNotSame('', $request->body);
    }

    public function testParsesAPlainHttpEndpoint(): void
    {
        $request = $this->factory->build(
            $this->config(),
            $this->subscription('http://localhost:8080/wp'),
            '{}',
        );

        self::assertSame('localhost', $request->host);
        self::assertSame(8080, $request->port);
        self::assertSame('/wp', $request->path);
        self::assertFalse($request->useTls);
    }

    /**
     * Builds a configured VAPID config with a fresh key pair.
     *
     * @return PushChannelConfig Configured push config
     */
    private function config(): PushChannelConfig
    {
        [$public, $private] = $this->ecKeyPair();

        return new PushChannelConfig($public, $private, 'mailto:ops@example.com');
    }

    /**
     * Builds an in-memory subscription with a fresh client key pair.
     *
     * @param string $endpoint Endpoint URL
     * @return ObjectPushSubscription Subscription carrying valid client encryption keys
     */
    private function subscription(string $endpoint): ObjectPushSubscription
    {
        [$public] = $this->ecKeyPair();

        $subscription = ObjectPushSubscription::create();
        $subscription->endpoint = $endpoint;
        $subscription->p256dh = $public;
        $subscription->auth = $this->base64url(random_bytes(16));

        return $subscription;
    }

    /**
     * Generates a fresh P-256 key pair, base64url-encoded (uncompressed point, raw scalar).
     *
     * @return array{0: string, 1: string} Public point and private scalar
     */
    private function ecKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($key, 'openssl EC key generation is available in the test image');
        $details = openssl_pkey_get_details($key);

        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $d = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);

        return [$this->base64url("\x04" . $x . $y), $this->base64url($d)];
    }

    /**
     * @param string $bin Raw bytes
     * @return string base64url without padding
     */
    private function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
