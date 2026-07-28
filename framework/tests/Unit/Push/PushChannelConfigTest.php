<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Push;

use Hilos\Push\Exception\PushConfigException;
use Hilos\Push\PushChannelConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests the resolved VAPID config the push agent signs with (HIL-199).
 *
 * A config is configured only when the key pair and the subject are all present; an
 * unconfigured or key-malformed config refuses to hand out VAPID material, throwing a
 * {@see PushConfigException} rather than letting a raw library error escape the tick
 * loop. A valid P-256 key pair validates and decodes to the material the signer expects.
 * The DB/env-backed resolve() is exercised through the channel and resolver tests.
 */
final class PushChannelConfigTest extends TestCase
{
    public function testUnconfiguredWhenAnyPartIsMissing(): void
    {
        self::assertFalse((new PushChannelConfig('', '', ''))->isConfigured());

        $keys = $this->vapidKeyPair();
        self::assertFalse((new PushChannelConfig($keys['public'], $keys['private'], ''))->isConfigured());
        self::assertFalse((new PushChannelConfig($keys['public'], '', 'mailto:ops@example.com'))->isConfigured());
    }

    public function testUnconfiguredConfigRefusesVapidMaterial(): void
    {
        $config = new PushChannelConfig('', '', '');

        $this->expectException(PushConfigException::class);
        $config->vapid();
    }

    public function testMalformedKeysRaiseAPushConfigException(): void
    {
        $config = new PushChannelConfig('not-a-key', 'also-not-a-key', 'mailto:ops@example.com');
        self::assertTrue($config->isConfigured());

        $this->expectException(PushConfigException::class);
        $config->vapid();
    }

    public function testValidKeyPairValidatesAndDecodes(): void
    {
        $keys = $this->vapidKeyPair();
        $config = new PushChannelConfig($keys['public'], $keys['private'], 'mailto:ops@example.com');

        self::assertTrue($config->isConfigured());

        $vapid = $config->vapid();
        self::assertSame('mailto:ops@example.com', $vapid['subject']);
        self::assertArrayHasKey('publicKey', $vapid);
        self::assertArrayHasKey('privateKey', $vapid);
        self::assertNotSame('', $vapid['publicKey']);
    }

    public function testCarriesTheTransportDefaults(): void
    {
        $config = new PushChannelConfig('', '', '');

        self::assertSame(PushChannelConfig::DEFAULT_TIMEOUT_MS, $config->timeoutMs);
        self::assertSame(PushChannelConfig::DEFAULT_TTL_SECONDS, $config->ttlSeconds);
    }

    /**
     * Generates a fresh P-256 VAPID key pair, base64url-encoded like a browser subscription.
     *
     * @return array{public: string, private: string} Application-server public and private keys
     */
    private function vapidKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($key, 'openssl EC key generation is available in the test image');
        $details = openssl_pkey_get_details($key);

        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $d = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);

        return [
            'public' => $this->base64url("\x04" . $x . $y),
            'private' => $this->base64url($d),
        ];
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
