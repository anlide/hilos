<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\WebAuthn;

use Hilos\Auth\WebAuthn\WebAuthnConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the resolved WebAuthn configuration value object (HIL-284).
 *
 * The config gates ceremony origins, derives the RP-id hash the verifiers compare
 * against, and reports whether policy demands user verification.
 */
final class WebAuthnConfigTest extends TestCase
{
    /**
     * Only an exactly-listed origin is allowed.
     */
    public function testAllowsOnlyConfiguredOrigins(): void
    {
        $config = $this->config(origins: ['https://example.com', 'https://www.example.com']);

        self::assertTrue($config->isAllowedOrigin('https://example.com'));
        self::assertTrue($config->isAllowedOrigin('https://www.example.com'));
        self::assertFalse($config->isAllowedOrigin('https://evil.example.com'));
        self::assertFalse($config->isAllowedOrigin('http://example.com'));
    }

    /**
     * The RP-id hash is the raw SHA-256 of the RP id.
     */
    public function testRpIdHashIsSha256OfRpId(): void
    {
        $config = $this->config(rpId: 'example.com');

        self::assertSame(hash('sha256', 'example.com', true), $config->rpIdHash());
    }

    /**
     * User verification is required only for the `required` level.
     */
    public function testRequiresUserVerificationOnlyWhenRequired(): void
    {
        self::assertTrue($this->config(userVerification: WebAuthnConfig::USER_VERIFICATION_REQUIRED)->requiresUserVerification());
        self::assertFalse($this->config(userVerification: WebAuthnConfig::USER_VERIFICATION_PREFERRED)->requiresUserVerification());
        self::assertFalse($this->config(userVerification: WebAuthnConfig::USER_VERIFICATION_DISCOURAGED)->requiresUserVerification());
    }

    /**
     * @param string $rpId RP id
     * @param list<string> $origins Allowed origins
     * @param string $userVerification UV level
     * @return WebAuthnConfig Config under test
     */
    private function config(
        string $rpId = 'localhost',
        array $origins = ['http://localhost'],
        string $userVerification = WebAuthnConfig::USER_VERIFICATION_PREFERRED,
    ): WebAuthnConfig {
        return new WebAuthnConfig($rpId, 'Hilos', $origins, 300, $userVerification, 60000, 'secret');
    }
}
