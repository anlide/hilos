<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * The fully-resolved configuration for one OAuth provider (HIL-281).
 *
 * The immutable recipe a {@see GenericOAuthProvider} runs on, the OAuth analog
 * of an LLM profile: authorize/token/userinfo endpoints, the client credential
 * pair, the requested scope, the SPA callback the provider redirects to, and the
 * field map naming the subject/email/name keys in the userinfo payload. `clientSecret`
 * is env-only (never synced to any client); the resolver reads it from env while
 * the rest may be settings-overridable. A project builds these from its provider
 * catalog and hands them to the registry.
 */
final readonly class OAuthProviderConfig
{
    /**
     * @param string $key Stable provider key, e.g. 'oauth:github'
     * @param string $clientId OAuth client id
     * @param string $clientSecret OAuth client secret (env-only)
     * @param string $authorizeUrl Provider authorization endpoint URL
     * @param string $tokenUrl Provider token endpoint URL
     * @param string $userInfoUrl Provider userinfo endpoint URL
     * @param string $scope Space-separated requested scopes
     * @param string $redirectUri SPA callback the provider redirects back to
     * @param string $subjectKey Userinfo field holding the immutable subject id
     * @param string $emailKey Userinfo field holding the account email
     * @param string $nameKey Userinfo field holding the display name
     */
    public function __construct(
        public string $key,
        public string $clientId,
        public string $clientSecret,
        public string $authorizeUrl,
        public string $tokenUrl,
        public string $userInfoUrl,
        public string $scope,
        public string $redirectUri,
        public string $subjectKey,
        public string $emailKey,
        public string $nameKey,
    ) {
    }
}
