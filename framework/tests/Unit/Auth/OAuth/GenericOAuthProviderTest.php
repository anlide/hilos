<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Auth\OAuth\Exception\OAuthProviderException;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Constants\HttpConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the config-driven OAuth2 client (HIL-281).
 *
 * Locks the URL/request building and the JSON response parsing (the parts most
 * likely to drift per provider) against a GitHub-shaped config.
 */
final class GenericOAuthProviderTest extends TestCase
{
    private function provider(): GenericOAuthProvider
    {
        return new GenericOAuthProvider(new OAuthProviderConfig(
            key: 'oauth:github',
            clientId: 'client-123',
            clientSecret: 'secret-xyz',
            authorizeUrl: 'https://github.com/login/oauth/authorize',
            tokenUrl: 'https://github.com/login/oauth/access_token',
            userInfoUrl: 'https://api.github.com/user',
            scope: 'read:user user:email',
            redirectUri: 'https://app.example/auth/callback',
            subjectKey: 'id',
            emailKey: 'email',
        ));
    }

    public function testBuildAuthorizeUrlCarriesTheOAuthParams(): void
    {
        $url = $this->provider()->buildAuthorizeUrl('state-token');

        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        self::assertStringContainsString('client_id=client-123', $url);
        self::assertStringContainsString('response_type=code', $url);
        self::assertStringContainsString('state=state-token', $url);
        self::assertStringContainsString('redirect_uri=https%3A%2F%2Fapp.example%2Fauth%2Fcallback', $url);
        self::assertStringContainsString('scope=read%3Auser%20user%3Aemail', $url);
    }

    public function testBuildTokenRequestTargetsTheTokenEndpoint(): void
    {
        $request = $this->provider()->buildTokenRequest('the-code');

        self::assertSame('github.com', $request->host);
        self::assertSame(443, $request->port);
        self::assertTrue($request->useTls);
        self::assertSame(HttpConstants::METHOD_POST, $request->method);
        self::assertSame('/login/oauth/access_token', $request->path);
        self::assertNotNull($request->body);
        self::assertStringContainsString('code=the-code', $request->body);
        self::assertStringContainsString('client_secret=secret-xyz', $request->body);
        self::assertStringContainsString('grant_type=authorization_code', $request->body);
        self::assertSame(HttpConstants::CONTENT_TYPE_JSON, $request->headers['Accept']);
    }

    public function testParseTokenResponseExtractsTheAccessToken(): void
    {
        $response = new AsyncHttpResponse(200, '', '{"access_token":"gho_abc","token_type":"bearer"}');

        self::assertSame('gho_abc', $this->provider()->parseTokenResponse($response));
    }

    public function testParseTokenResponseRejectsAMissingToken(): void
    {
        $response = new AsyncHttpResponse(200, '', '{"error":"bad_verification_code"}');

        $this->expectException(OAuthProviderException::class);
        $this->provider()->parseTokenResponse($response);
    }

    public function testBuildUserInfoRequestSetsTheBearerHeader(): void
    {
        $request = $this->provider()->buildUserInfoRequest('gho_abc');

        self::assertSame('api.github.com', $request->host);
        self::assertSame(HttpConstants::METHOD_GET, $request->method);
        self::assertSame('/user', $request->path);
        self::assertNull($request->body);
        self::assertSame('Bearer gho_abc', $request->headers['Authorization']);
    }

    public function testParseUserInfoResponseMapsSubjectAndEmail(): void
    {
        $response = new AsyncHttpResponse(200, '', '{"id":4711,"email":"User@Example.com","login":"octo"}');

        $info = $this->provider()->parseUserInfoResponse($response);

        self::assertSame('4711', $info->subject);
        self::assertSame('user@example.com', $info->email);
    }

    public function testParseUserInfoResponseRejectsAMissingSubject(): void
    {
        $response = new AsyncHttpResponse(200, '', '{"email":"user@example.com"}');

        $this->expectException(OAuthProviderException::class);
        $this->provider()->parseUserInfoResponse($response);
    }
}
