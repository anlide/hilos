<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\API\AsyncHttpClient;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Auth\OAuth\Exception\OAuthProviderException;

/**
 * An OAuth provider whose exchange runs over real HTTP (HIL-281).
 *
 * The provider does no I/O itself: it builds the token and userinfo requests and
 * parses their responses, while the OAuth async agent owns the non-blocking
 * {@see AsyncHttpClient} and pumps them across event-loop ticks. This
 * keeps the token/userinfo round-trips off the master and out of any synchronous
 * page action. {@see GenericOAuthProvider} is the config-driven implementation.
 */
interface HttpOAuthProvider extends OAuthProviderInterface
{
    /**
     * Builds the token-exchange request (authorization code → access token).
     *
     * @param string $code Authorization code returned to the SPA callback
     * @return OAuthHttpRequest Request the agent replays to the token endpoint
     */
    public function buildTokenRequest(string $code): OAuthHttpRequest;

    /**
     * Extracts the access token from the token endpoint response.
     *
     * @param AsyncHttpResponse $response Completed token endpoint response
     * @return string Access token
     * @throws OAuthProviderException When the body is malformed or has no token
     */
    public function parseTokenResponse(AsyncHttpResponse $response): string;

    /**
     * Builds the userinfo request authenticated with the access token.
     *
     * @param string $accessToken Access token from the token exchange
     * @return OAuthHttpRequest Request the agent replays to the userinfo endpoint
     */
    public function buildUserInfoRequest(string $accessToken): OAuthHttpRequest;

    /**
     * Maps the userinfo response to a resolved account identity.
     *
     * @param AsyncHttpResponse $response Completed userinfo endpoint response
     * @return OAuthUserInfo Resolved subject/email
     * @throws OAuthProviderException When the body is malformed or has no subject
     */
    public function parseUserInfoResponse(AsyncHttpResponse $response): OAuthUserInfo;
}
