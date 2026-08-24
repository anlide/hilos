<?php

declare(strict_types=1);

namespace Demo\Chat\Auth;

use Demo\Chat\Agents\OAuthAgent;
use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Hilos;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderPreset;
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
 * {@see OAuthAgent} drives. It mirrors the LLM
 * local-vs-external switch: when a provider's client id + secret are configured a
 * real {@see GenericOAuthProvider} is built; otherwise the offline
 * {@see StubOAuthProvider} is handed to the registry under the same key, so dev/e2e
 * log in with no real round-trip. Whether that stub survives is not this class's
 * call: the registry refuses an offline provider on a production-like node
 * (HIL-671), so an unconfigured provider there is simply absent rather than open.
 * A settings-override source (the OAuth analog of HIL-262) is a later concern;
 * config is env-only here.
 */
final class ChatOAuthConfig
{
    /** Lifetime of a minted `state` token: long enough for a human redirect, short enough to bound replay. */
    private const int STATE_TTL_SECONDS = 600;

    /**
     * Lifetime of a minted account-link token (HIL-282): the window to complete a
     * full re-authentication and redeem the link, short enough to bound replay.
     */
    private const int LINK_TOKEN_TTL_SECONDS = 600;

    /**
     * The providers this demo enables, each with the env keys carrying its client pair.
     *
     * The one place that declares the set, and its order is the order of the icon
     * row: the registry hands its keys back as configured and the surface draws
     * them in that order. A row, and not a map keyed by the preset, only because a
     * PHP constant array cannot be keyed by an enum case.
     *
     * A recipe is not here at all — it is the framework's ({@see OAuthProviderPreset}).
     * A project that needs a provider Hilos ships no preset for builds an
     * {@see OAuthProviderConfig} by hand and registers it the same way.
     *
     * @var list<array{0: OAuthProviderPreset, 1: string, 2: string}> Preset, client id key, client secret key
     */
    private const array PROVIDER_CREDENTIALS = [
        [
            OAuthProviderPreset::GITHUB,
            ChatEnvConstants::OAUTH_GITHUB_CLIENT_ID,
            ChatEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
        ],
        [
            OAuthProviderPreset::GOOGLE,
            ChatEnvConstants::OAUTH_GOOGLE_CLIENT_ID,
            ChatEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET,
        ],
    ];

    /**
     * Builds the synchronous OAuth service for the page actions and the async agent.
     *
     * The state signer and the account-link signer (HIL-282) share the one OAuth
     * app secret; {@see OAuthLinkTokenSigner} keeps them cryptographically distinct
     * with its domain tag, so no separate secret is provisioned.
     *
     * @return OAuthService Service over the configured providers, state signer, and link signer
     */
    public static function buildService(): OAuthService
    {
        $appSecret = Hilos::$env[ChatEnvConstants::OAUTH_STATE_SECRET];

        return new OAuthService(
            self::buildProviderRegistry(),
            new OAuthStateSigner($appSecret),
            self::STATE_TTL_SECONDS,
            new OAuthLinkTokenSigner($appSecret),
            self::LINK_TOKEN_TTL_SECONDS,
        );
    }

    /**
     * Builds the configured provider registry (real providers, or offline stubs).
     *
     * @return OAuthProviderRegistry Providers keyed by provider key, in enabled order
     */
    public static function buildProviderRegistry(): OAuthProviderRegistry
    {
        $providers = [];
        foreach (self::PROVIDER_CREDENTIALS as [$preset, $clientIdKey, $clientSecretKey]) {
            $providers[] = self::providerFor($preset, $clientIdKey, $clientSecretKey);
        }

        return new OAuthProviderRegistry($providers);
    }

    /**
     * Resolves one provider: a real one when its credentials are set, the offline stub otherwise.
     *
     * The stub is offered unconditionally and dropped selectively: on a production-like
     * node {@see OAuthProviderRegistry} refuses it, which is why this method needs to know
     * nothing about the environment (HIL-671).
     *
     * @param OAuthProviderPreset $preset Framework recipe for this provider
     * @param string $clientIdKey Env key carrying the client id
     * @param string $clientSecretKey Env key carrying the client secret
     * @return GenericOAuthProvider|StubOAuthProvider Configured provider under the preset's key
     */
    private static function providerFor(
        OAuthProviderPreset $preset,
        string $clientIdKey,
        string $clientSecretKey,
    ): GenericOAuthProvider|StubOAuthProvider {
        $clientId = Hilos::$env[$clientIdKey];
        $clientSecret = Hilos::$env[$clientSecretKey];
        $redirectUri = Hilos::$env[ChatEnvConstants::OAUTH_REDIRECT_URI];

        if ($clientId === '' || $clientSecret === '') {
            return new StubOAuthProvider($preset->value, $redirectUri, self::stubCode($preset));
        }

        return new GenericOAuthProvider($preset->config($clientId, $clientSecret, $redirectUri));
    }

    /**
     * The canned code one provider's offline stub bounces back with.
     *
     * Per provider and not shared: the stub derives its account from the code, so
     * one code for both would hand them the same `<code>@stub.local` address, and
     * signing in with the second provider in dev would always land in cross-provider
     * account linking (HIL-282) instead of a plain sign-in.
     *
     * @param OAuthProviderPreset $preset Provider whose stub is being built
     * @return string Provider name, the part of its key after the `oauth:` prefix
     */
    private static function stubCode(OAuthProviderPreset $preset): string
    {
        return str_replace(AuthMethodKey::OAUTH_PREFIX, '', $preset->value);
    }
}
