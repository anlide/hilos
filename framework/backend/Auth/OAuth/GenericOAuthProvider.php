<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Auth\OAuth\Exception\OAuthProviderException;
use Hilos\Constants\HttpConstants;

/**
 * A config-driven OAuth2 client for any standard authorization-code provider
 * (HIL-281).
 *
 * One implementation covers every provider that speaks the plain OAuth2 code
 * flow with a JSON userinfo endpoint (GitHub is the reference dev instance —
 * simplest, no OIDC / no id_token JWT to verify). Providers differ only by
 * their {@see OAuthProviderConfig}: endpoint URLs, credentials, scope, and the
 * field map naming the subject/email keys. Providers that need OIDC/JWT
 * verification are a later, separate implementation, not a config change here.
 */
final class GenericOAuthProvider implements HttpOAuthProvider
{
    private const string CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';
    private const string HEADER_ACCEPT = 'Accept';
    private const string HEADER_AUTHORIZATION = 'Authorization';

    public function __construct(
        private readonly OAuthProviderConfig $config,
    ) {
    }

    public function getKey(): string
    {
        return $this->config->key;
    }

    public function buildAuthorizeUrl(string $state): string
    {
        $query = http_build_query(
            [
                'client_id' => $this->config->clientId,
                'redirect_uri' => $this->config->redirectUri,
                'scope' => $this->config->scope,
                'response_type' => 'code',
                'state' => $state,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        $separator = str_contains($this->config->authorizeUrl, '?') ? '&' : '?';

        return $this->config->authorizeUrl . $separator . $query;
    }

    public function buildTokenRequest(string $code): OAuthHttpRequest
    {
        $body = http_build_query(
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'redirect_uri' => $this->config->redirectUri,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return $this->requestFor(
            $this->config->tokenUrl,
            HttpConstants::METHOD_POST,
            $body,
            [
                HttpConstants::HEADER_CONTENT_TYPE => self::CONTENT_TYPE_FORM,
                self::HEADER_ACCEPT => HttpConstants::CONTENT_TYPE_JSON,
            ],
        );
    }

    public function parseTokenResponse(AsyncHttpResponse $response): string
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new OAuthProviderException('OAuth token response is not a JSON object');
        }

        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new OAuthProviderException('OAuth token response has no access token');
        }

        return $token;
    }

    public function buildUserInfoRequest(string $accessToken): OAuthHttpRequest
    {
        return $this->requestFor(
            $this->config->userInfoUrl,
            HttpConstants::METHOD_GET,
            null,
            [
                self::HEADER_AUTHORIZATION => 'Bearer ' . $accessToken,
                self::HEADER_ACCEPT => HttpConstants::CONTENT_TYPE_JSON,
            ],
        );
    }

    public function parseUserInfoResponse(AsyncHttpResponse $response): OAuthUserInfo
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new OAuthProviderException('OAuth userinfo response is not a JSON object');
        }

        $subjectRaw = $decoded[$this->config->subjectKey] ?? null;
        if (!is_scalar($subjectRaw) || (string)$subjectRaw === '') {
            throw new OAuthProviderException('OAuth userinfo response has no subject');
        }

        $emailRaw = $decoded[$this->config->emailKey] ?? null;
        $email = is_string($emailRaw) ? strtolower($emailRaw) : '';

        return new OAuthUserInfo((string)$subjectRaw, $email);
    }

    /**
     * Splits an endpoint URL into the connection parts the async client needs.
     *
     * @param string $url Absolute endpoint URL
     * @param string $method HTTP method (see HttpConstants)
     * @param ?string $body Request body, or null
     * @param array<string, string> $headers Request headers
     * @return OAuthHttpRequest Request descriptor for the endpoint
     * @throws OAuthProviderException When the URL has no host
     */
    private function requestFor(string $url, string $method, ?string $body, array $headers): OAuthHttpRequest
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!is_string($host) || $host === '') {
            throw new OAuthProviderException('OAuth endpoint URL has no host: ' . $url);
        }

        $useTls = ($parts['scheme'] ?? 'https') === 'https';
        $port = $parts['port'] ?? ($useTls ? 443 : 80);

        $path = $parts['path'] ?? '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return new OAuthHttpRequest($host, (int)$port, $useTls, $method, $path, $headers, $body);
    }
}
