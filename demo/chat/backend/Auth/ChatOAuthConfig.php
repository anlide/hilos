<?php

declare(strict_types=1);

namespace Demo\Chat\Auth;

use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Hilos;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\OAuth\StubOAuthProvider;

/**
 * ChatOAuthConfig - the chat demo's OAuth provider wiring (HIL-281).
 *
 * The single place that turns env config into the framework OAuth objects both
 * halves of the flow need: the {@see OAuthService} the `oauthStart`/`oauthCallback`
 * page actions run on, and the {@see OAuthProviderRegistry} the async
 * {@see \Demo\Chat\Agents\OAuthAgent} drives. It mirrors the LLM
 * local-vs-external switch: when a provider's client id + secret are configured a
 * real {@see GenericOAuthProvider} is built; otherwise the offline
 * {@see StubOAuthProvider} answers under the same key, so dev/e2e log in with no
 * real round-trip. A settings-override source (the OAuth analog of HIL-262) is a
 * later concern; config is env-only here.
 */
final class ChatOAuthConfig
{
    /** The one provider key this demo exposes; matches the frontend auth descriptor. */
    public const string PROVIDER_GITHUB = 'oauth:github';

    /**
     * Lifetime of an in-flight OAuth exchange op after the callback records it: two
     * provider round-trips plus slack, after which the async agent abandons it.
     */
    public const float EXCHANGE_TTL_MS = 15000.0;

    /** Lifetime of a minted `state` token: long enough for a human redirect, short enough to bound replay. */
    private const int STATE_TTL_SECONDS = 600;

    /** GitHub authorization endpoint. */
    private const string GITHUB_AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

    /** GitHub token endpoint. */
    private const string GITHUB_TOKEN_URL = 'https://github.com/login/oauth/access_token';

    /** GitHub userinfo endpoint. */
    private const string GITHUB_USERINFO_URL = 'https://api.github.com/user';

    /** GitHub scopes for identity + verified email resolution. */
    private const string GITHUB_SCOPE = 'read:user user:email';

    /** Userinfo field carrying the immutable GitHub account id. */
    private const string GITHUB_SUBJECT_KEY = 'id';

    /** Userinfo field carrying the GitHub account email. */
    private const string GITHUB_EMAIL_KEY = 'email';

    /**
     * Builds the synchronous OAuth service for the page actions.
     *
     * @return OAuthService Service over the configured providers and state signer
     */
    public static function buildService(): OAuthService
    {
        return new OAuthService(
            self::buildProviderRegistry(),
            new OAuthStateSigner(Hilos::$env[ChatEnvConstants::OAUTH_STATE_SECRET]),
            self::STATE_TTL_SECONDS,
        );
    }

    /**
     * Builds the configured provider registry (real provider, or offline stub).
     *
     * @return OAuthProviderRegistry Providers keyed by provider key
     */
    public static function buildProviderRegistry(): OAuthProviderRegistry
    {
        return new OAuthProviderRegistry([self::githubProvider()]);
    }

    /**
     * Resolves the GitHub provider: a real one when credentials are set, the offline stub otherwise.
     *
     * @return GenericOAuthProvider|StubOAuthProvider Configured provider under PROVIDER_GITHUB
     */
    private static function githubProvider(): GenericOAuthProvider|StubOAuthProvider
    {
        $clientId = Hilos::$env[ChatEnvConstants::OAUTH_GITHUB_CLIENT_ID];
        $clientSecret = Hilos::$env[ChatEnvConstants::OAUTH_GITHUB_CLIENT_SECRET];
        $redirectUri = Hilos::$env[ChatEnvConstants::OAUTH_GITHUB_REDIRECT_URI];

        if ($clientId === '' || $clientSecret === '') {
            return new StubOAuthProvider(self::PROVIDER_GITHUB, $redirectUri);
        }

        return new GenericOAuthProvider(new OAuthProviderConfig(
            key: self::PROVIDER_GITHUB,
            clientId: $clientId,
            clientSecret: $clientSecret,
            authorizeUrl: self::GITHUB_AUTHORIZE_URL,
            tokenUrl: self::GITHUB_TOKEN_URL,
            userInfoUrl: self::GITHUB_USERINFO_URL,
            scope: self::GITHUB_SCOPE,
            redirectUri: $redirectUri,
            subjectKey: self::GITHUB_SUBJECT_KEY,
            emailKey: self::GITHUB_EMAIL_KEY,
        ));
    }
}
