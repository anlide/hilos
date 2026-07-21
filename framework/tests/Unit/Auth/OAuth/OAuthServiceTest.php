<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Exception\OAuthStateException;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\OAuth\StubOAuthProvider;
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

    private function service(): OAuthService
    {
        $registry = new OAuthProviderRegistry([
            new StubOAuthProvider(StubOAuthProvider::DEFAULT_KEY, 'https://app.example/auth/callback'),
        ]);

        return new OAuthService($registry, new OAuthStateSigner('unit-secret'), 600);
    }

    /**
     * beginAuthorization yields a URL whose state the facade then verifies for
     * the same session.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     * @throws OAuthStateException Never in the success path
     */
    public function testBeginAuthorizationMintsAStateThatVerifies(): void
    {
        $service = $this->service();

        $url = $service->beginAuthorization(StubOAuthProvider::DEFAULT_KEY, self::SESSION);

        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertArrayHasKey('state', $params);
        self::assertIsString($params['state']);

        $service->verifyState($params['state'], self::SESSION);
        self::assertStringStartsWith('https://app.example/auth/callback?', $url);
    }

    /**
     * An unknown provider key is rejected synchronously at start.
     *
     * @throws RandomException When the CSPRNG cannot produce a nonce
     */
    public function testBeginAuthorizationRejectsUnknownProvider(): void
    {
        $this->expectException(OAuthUnknownProviderException::class);
        $this->service()->beginAuthorization('oauth:nope', self::SESSION);
    }

    /**
     * providerFor returns the configured provider and rejects the unknown.
     */
    public function testProviderForResolvesAndRejects(): void
    {
        $service = $this->service();

        self::assertSame(
            StubOAuthProvider::DEFAULT_KEY,
            $service->providerFor(StubOAuthProvider::DEFAULT_KEY)->getKey(),
        );

        $this->expectException(OAuthUnknownProviderException::class);
        $service->providerFor('oauth:nope');
    }
}
