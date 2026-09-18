<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * OAuthProviderPreset - the OAuth recipes Hilos ships (HIL-419).
 *
 * A provider's recipe - endpoints, scope, and the userinfo field map - is the
 * same for every project that talks to that provider, so it lives here and a
 * project supplies only what is truly its own: the client credential pair and
 * the callback address of its own SPA.
 *
 * The preset is a SHORT way in, not the only one. Building an
 * {@see OAuthProviderConfig} by hand and putting it in the
 * {@see OAuthProviderRegistry} stays open, and that is how a provider Hilos has
 * never heard of is connected - nothing here narrows that path.
 *
 * A provider that hands identity out as a signed `id_token` (OIDC proper) is not
 * a preset away: {@see GenericOAuthProvider} reads a userinfo JSON and verifies no
 * JWT, which is its declared boundary. Such a provider needs its own
 * implementation of {@see OAuthProviderInterface}.
 *
 * The endpoints of a preset are redirected by one variable, OAUTH_ENDPOINT_URL,
 * and it is configurable for exactly one reason: the test stand runs its own
 * provider emulator, and a suite that cannot send the sign-in to it can only test
 * the provider path by not testing it (HIL-924). In production it is empty and
 * every preset talks to its provider. Only the presets follow it - an
 * {@see OAuthProviderConfig} a project builds by hand keeps its own endpoints.
 */
enum OAuthProviderPreset: string
{
    case GITHUB = AuthMethodKey::OAUTH_PREFIX . 'github';

    case GOOGLE = AuthMethodKey::OAUTH_PREFIX . 'google';

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
     * Userinfo field carrying the GitHub display name.
     *
     * `login` and not `name`: a GitHub profile name is optional and often blank,
     * while the login handle is always there - and a name the provider withheld
     * costs the new account its human-readable name (HIL-573).
     */
    private const string GITHUB_NAME_KEY = 'login';

    /** Google authorization endpoint. */
    private const string GOOGLE_AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** Google token endpoint. */
    private const string GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Google userinfo endpoint.
     *
     * The OpenID userinfo endpoint and not the `id_token` the token response also
     * carries: the generic client reads a userinfo JSON over a Bearer request and
     * verifies no JWT, so the claims are taken from where they need no verifying.
     */
    private const string GOOGLE_USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** Google scopes for identity + email resolution. */
    private const string GOOGLE_SCOPE = 'openid email profile';

    /** Userinfo claim carrying the immutable Google account id. */
    private const string GOOGLE_SUBJECT_KEY = 'sub';

    /** Userinfo claim carrying the Google account email. */
    private const string GOOGLE_EMAIL_KEY = 'email';

    /** Userinfo claim carrying the Google display name. */
    private const string GOOGLE_NAME_KEY = 'name';

    /** Last path segment of the authorization endpoint under OAUTH_ENDPOINT_URL. */
    private const string ENDPOINT_AUTHORIZE = 'authorize';

    /** Last path segment of the token endpoint under OAUTH_ENDPOINT_URL. */
    private const string ENDPOINT_TOKEN = 'token';

    /** Last path segment of the userinfo endpoint under OAUTH_ENDPOINT_URL. */
    private const string ENDPOINT_USERINFO = 'userinfo';

    /** Separator between the path segments of a redirected endpoint. */
    private const string PATH_SEPARATOR = '/';

    /**
     * Fills this preset's recipe in with the project's own client data.
     *
     * The endpoints are the provider's own unless OAUTH_ENDPOINT_URL names a base,
     * in which case each one is `<base>/<provider>/authorize|token|userinfo`.
     *
     * @param string $clientId OAuth client id issued to the project
     * @param string $clientSecret OAuth client secret issued to the project (env-only)
     * @param string $redirectUri SPA callback the provider redirects back to
     * @return OAuthProviderConfig Config under this preset's key
     * @throws EnvException When OAUTH_ENDPOINT_URL is missing, outside the catalog, or of the wrong type
     */
    public function config(string $clientId, string $clientSecret, string $redirectUri): OAuthProviderConfig
    {
        $endpointUrl = Hilos::$env[EnvConstants::OAUTH_ENDPOINT_URL]->string();

        return match ($this) {
            self::GITHUB => new OAuthProviderConfig(
                key: $this->value,
                clientId: $clientId,
                clientSecret: $clientSecret,
                authorizeUrl: $this->endpoint($endpointUrl, self::ENDPOINT_AUTHORIZE, self::GITHUB_AUTHORIZE_URL),
                tokenUrl: $this->endpoint($endpointUrl, self::ENDPOINT_TOKEN, self::GITHUB_TOKEN_URL),
                userInfoUrl: $this->endpoint($endpointUrl, self::ENDPOINT_USERINFO, self::GITHUB_USERINFO_URL),
                scope: self::GITHUB_SCOPE,
                redirectUri: $redirectUri,
                subjectKey: self::GITHUB_SUBJECT_KEY,
                emailKey: self::GITHUB_EMAIL_KEY,
                nameKey: self::GITHUB_NAME_KEY,
            ),
            self::GOOGLE => new OAuthProviderConfig(
                key: $this->value,
                clientId: $clientId,
                clientSecret: $clientSecret,
                authorizeUrl: $this->endpoint($endpointUrl, self::ENDPOINT_AUTHORIZE, self::GOOGLE_AUTHORIZE_URL),
                tokenUrl: $this->endpoint($endpointUrl, self::ENDPOINT_TOKEN, self::GOOGLE_TOKEN_URL),
                userInfoUrl: $this->endpoint($endpointUrl, self::ENDPOINT_USERINFO, self::GOOGLE_USERINFO_URL),
                scope: self::GOOGLE_SCOPE,
                redirectUri: $redirectUri,
                subjectKey: self::GOOGLE_SUBJECT_KEY,
                emailKey: self::GOOGLE_EMAIL_KEY,
                nameKey: self::GOOGLE_NAME_KEY,
            ),
        };
    }

    /**
     * One endpoint of this preset: the provider's own, or its path under the redirect base.
     *
     * A trailing slash on the base is forgiven, as it is on any base URL.
     *
     * @param string $endpointUrl OAUTH_ENDPOINT_URL as read; empty keeps the provider's own endpoint
     * @param string $segment Last path segment of the endpoint under the base
     * @param string $providerUrl The provider's own endpoint
     * @return string Endpoint URL the generic client calls
     */
    private function endpoint(string $endpointUrl, string $segment, string $providerUrl): string
    {
        if ($endpointUrl === '') {
            return $providerUrl;
        }

        return rtrim($endpointUrl, self::PATH_SEPARATOR)
            . self::PATH_SEPARATOR . $this->providerName()
            . self::PATH_SEPARATOR . $segment;
    }

    /**
     * The provider's name without the auth-method prefix - `github`, `google`.
     *
     * It is the profile name the stand's emulator serves the provider under.
     *
     * @return string Preset key without {@see AuthMethodKey::OAUTH_PREFIX}
     */
    private function providerName(): string
    {
        return str_replace(AuthMethodKey::OAUTH_PREFIX, '', $this->value);
    }
}
