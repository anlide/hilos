<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\Base64Url;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared WebAuthn base64url codec (HIL-284).
 *
 * The codec must round-trip arbitrary binary as URL-safe, unpadded base64 and
 * reject anything that is not strictly valid, so the challenge signer and the
 * verifiers agree byte-for-byte on every wire value.
 */
final class Base64UrlTest extends TestCase
{
    /**
     * Binary with bytes that map to the URL-unsafe `+` and `/` round-trips.
     */
    public function testRoundTripsBinaryWithUrlUnsafeBytes(): void
    {
        $raw = "\xff\xfe\xfd\x00\x10\x3e\x3f";

        $encoded = Base64Url::encode($raw);

        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
        self::assertSame($raw, Base64Url::decode($encoded));
    }

    /**
     * A malformed base64url string decodes to null rather than a lossy value.
     */
    public function testDecodeRejectsInvalidInput(): void
    {
        self::assertNull(Base64Url::decode('not valid base64 !!!'));
    }
}
