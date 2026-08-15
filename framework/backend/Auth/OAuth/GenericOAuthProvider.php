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
 * field map naming the subject/email/name keys. Providers that need OIDC/JWT
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

    /**
     * @param string $code Authorization code returned to the SPA callback
     * @return OAuthHttpRequest Request the agent replays to the token endpoint
     * @throws OAuthProviderException When the configured token endpoint is not a usable URL
     */
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

    /**
     * @param AsyncHttpResponse $response Completed token endpoint response
     * @return string Access token
     * @throws OAuthProviderException When the body is malformed or has no token
     */
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

    /**
     * @param string $accessToken Access token from the token exchange
     * @return OAuthHttpRequest Request the agent replays to the userinfo endpoint
     * @throws OAuthProviderException When the configured userinfo endpoint is not a usable URL
     */
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

    /**
     * @param AsyncHttpResponse $response Completed userinfo endpoint response
     * @return OAuthUserInfo Resolved subject, email and name
     * @throws OAuthProviderException When the body is malformed or has no subject
     */
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

        $email = $this->optionalField($decoded, $this->config->emailKey);

        return new OAuthUserInfo(
            (string)$subjectRaw,
            $email === null ? null : mb_strtolower($email),
            $this->optionalField($decoded, $this->config->nameKey),
        );
    }

    /**
     * Reads one optional userinfo field, turning provider absence into null.
     *
     * The provider's userinfo JSON may carry no address and no name at all — the
     * key can be missing, hold JSON null, or hold a blank string — and all three
     * spellings of "the provider withheld it" answer the same null (HIL-573).
     *
     * @param array<mixed> $decoded Decoded userinfo payload
     * @param string $key Field name from the provider's field map
     * @return ?string Trimmed field value, or null when the provider withheld it
     */
    private function optionalField(array $decoded, string $key): ?string
    {
        $raw = $decoded[$key] ?? null;
        if (!is_string($raw)) {
            return null;
        }

        $value = trim($raw);

        return $value === '' ? null : $value;
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
