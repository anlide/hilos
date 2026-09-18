<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Exception\OAuthStateException;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Environment\Exception\EnvException;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * Unit tests for the synchronous OAuth facade (HIL-281).
 *
 * The start action mints a provider URL + session-bound state, the callback
 * gate verifies that state, and both reject an unconfigured provider — all
 * without touching the network (the exchange is the async agent's job).
 */
final class OAuthServiceTest extends TestCase
{
    private const string SESSION = 'session-token-abc123';

    /**
     * The provider every case configures: a real one over the GitHub preset.
     *
     * @return OAuthProviderConfig GitHub preset filled with this test's client data
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    private function providerConfig(): OAuthProviderConfig
    {
        return OAuthProviderPreset::GITHUB->config('client-123', 'secret-xyz', 'https://app.example/auth/callback');
    }

    /**
     * @return OAuthService Facade over a registry holding the one configured provider
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    private function service(): OAuthService
    {
        $registry = new OAuthProviderRegistry([new GenericOAuthProvider($this->providerConfig())]);

        return new OAuthService(
            $registry,
            new OAuthStateSigner('unit-secret'),
            600,
            new OAuthLinkTokenSigner('unit-secret'),
            600,
        );
    }

    /**
     * beginAuthorization yields a URL whose state the facade then verifies for
     * the same session.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     * @throws OAuthStateException Never in the success path
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testBeginAuthorizationMintsAStateThatVerifies(): void
    {
        $service = $this->service();

        $url = $service->beginAuthorization(OAuthProviderPreset::GITHUB->value, self::SESSION);

        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertArrayHasKey('state', $params);
        self::assertIsString($params['state']);

        $mode = $service->verifyState($params['state'], self::SESSION);
        self::assertSame(OAuthStateSigner::MODE_LOGIN, $mode);
        self::assertStringStartsWith($this->providerConfig()->authorizeUrl . '?', $url);
    }

    /**
     * A link-mode start binds link mode into the state, which the facade verifies back (HIL-401).
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     * @throws OAuthStateException Never in the success path
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testBeginAuthorizationBindsLinkModeThatVerifiesBack(): void
    {
        $service = $this->service();

        $url = $service->beginAuthorization(
            OAuthProviderPreset::GITHUB->value,
            self::SESSION,
            OAuthStateSigner::MODE_LINK,
        );

        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertIsString($params['state']);

        self::assertSame(OAuthStateSigner::MODE_LINK, $service->verifyState($params['state'], self::SESSION));
    }

    /**
     * An unknown provider key is rejected synchronously at start.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testBeginAuthorizationRejectsUnknownProvider(): void
    {
        $this->expectException(OAuthUnknownProviderException::class);
        $this->service()->beginAuthorization('oauth:nope', self::SESSION);
    }

    /**
     * providerFor returns the configured provider and rejects the unknown.
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testProviderForResolvesAndRejects(): void
    {
        $service = $this->service();

        self::assertSame(
            OAuthProviderPreset::GITHUB->value,
            $service->providerFor(OAuthProviderPreset::GITHUB->value)->getKey(),
        );

        $this->expectException(OAuthUnknownProviderException::class);
        $service->providerFor('oauth:nope');
    }

    /**
     * issueLinkToken mints a link token the same service then verifies back to its
     * provider, subject, and email (HIL-282).
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testIssueLinkTokenMintsATokenThatVerifiesBack(): void
    {
        $service = $this->service();

        $token = $service->issueLinkToken(OAuthProviderPreset::GITHUB->value, '4242', 'user@example.com');

        $data = $service->verifyLinkToken($token);
        self::assertNotNull($data);
        self::assertSame(OAuthProviderPreset::GITHUB->value, $data->provider);
        self::assertSame('4242', $data->subject);
        self::assertSame('user@example.com', $data->email);
    }

    /**
     * A tampered link token does not verify (HIL-282).
     *
     * @throws EnvException When OAUTH_ENDPOINT_URL cannot be read
     */
    public function testVerifyLinkTokenRejectsATamperedToken(): void
    {
        $service = $this->service();

        $token = $service->issueLinkToken(OAuthProviderPreset::GITHUB->value, '4242', 'user@example.com');

        self::assertNull($service->verifyLinkToken($token . 'x'));
    }
}
