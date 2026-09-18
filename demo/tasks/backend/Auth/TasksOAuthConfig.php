<?php

declare(strict_types=1);

namespace Demo\Tasks\Auth;

use Demo\Tasks\Agents\OAuthAgent;
use Demo\Tasks\Constants\TasksEnvConstants;
use Demo\Tasks\Hilos;
use Hilos\Auth\OAuth\GenericOAuthProvider;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthProviderConfig;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;

/**
 * TasksOAuthConfig - the tasks demo's OAuth provider wiring (HIL-623).
 *
 * The single place that turns env config into the framework OAuth objects both halves of
 * the flow need: the {@see OAuthService} the users library runs its provider commands on,
 * and the {@see OAuthProviderRegistry} the async {@see OAuthAgent} drives. When a
 * provider's client id + secret are configured a real {@see GenericOAuthProvider} is
 * built over the framework's preset; when either is empty the provider is left out - no
 * icon, no sign-in - on every node alike (HIL-924). The test stand fills a fake pair and
 * points the presets at its own provider emulator, so the e2e signs in through a real
 * redirect and exchange.
 */
final class TasksOAuthConfig
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
     * The one place that declares the set, and its order is the order of the icon row: the
     * registry hands its keys back as configured and the surface draws them in that order.
     * A row, and not a map keyed by the preset, only because a PHP constant array cannot be
     * keyed by an enum case.
     *
     * A recipe is not here at all - it is the framework's ({@see OAuthProviderPreset}). A
     * project that needs a provider Hilos ships no preset for builds an
     * {@see OAuthProviderConfig} by hand and registers it the same way.
     *
     * @var list<array{0: OAuthProviderPreset, 1: string, 2: string}> Preset, client id key, client secret key
     */
    private const array PROVIDER_CREDENTIALS = [
        [
            OAuthProviderPreset::GITHUB,
            TasksEnvConstants::OAUTH_GITHUB_CLIENT_ID,
            TasksEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
        ],
        [
            OAuthProviderPreset::GOOGLE,
            TasksEnvConstants::OAUTH_GOOGLE_CLIENT_ID,
            TasksEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET,
        ],
    ];

    /**
     * Builds the synchronous OAuth service the provider commands run on.
     *
     * The state signer and the account-link signer (HIL-282) share the one OAuth app
     * secret; {@see OAuthLinkTokenSigner} keeps them cryptographically distinct with its
     * domain tag, so no separate secret is provisioned.
     *
     * @return OAuthService Service over the configured providers, state signer, and link signer
     */
    public static function buildService(): OAuthService
    {
        $appSecret = Hilos::$env[TasksEnvConstants::OAUTH_STATE_SECRET]->string();

        return new OAuthService(
            self::buildProviderRegistry(),
            new OAuthStateSigner($appSecret),
            self::STATE_TTL_SECONDS,
            new OAuthLinkTokenSigner($appSecret),
            self::LINK_TOKEN_TTL_SECONDS,
        );
    }

    /**
     * Builds the registry of the providers whose client pair is configured.
     *
     * @return OAuthProviderRegistry Providers keyed by provider key, in enabled order
     */
    public static function buildProviderRegistry(): OAuthProviderRegistry
    {
        $providers = [];
        foreach (self::PROVIDER_CREDENTIALS as [$preset, $clientIdKey, $clientSecretKey]) {
            $provider = self::providerFor($preset, $clientIdKey, $clientSecretKey);
            if ($provider !== null) {
                $providers[] = $provider;
            }
        }

        return new OAuthProviderRegistry($providers);
    }

    /**
     * Resolves one provider: a real one when its credentials are set, none otherwise.
     *
     * An empty pair is not an error but a provider this installation does not offer, the
     * same on every node: left out of the registry, it is gone from the icon row, from the
     * identifier detection and from the agent at once.
     *
     * @param OAuthProviderPreset $preset Framework recipe for this provider
     * @param string $clientIdKey Env key carrying the client id
     * @param string $clientSecretKey Env key carrying the client secret
     * @return ?GenericOAuthProvider Configured provider under the preset's key, or null when its pair is empty
     */
    private static function providerFor(
        OAuthProviderPreset $preset,
        string $clientIdKey,
        string $clientSecretKey,
    ): ?GenericOAuthProvider {
        $clientId = Hilos::$env[$clientIdKey]->string();
        $clientSecret = Hilos::$env[$clientSecretKey]->string();
        $redirectUri = Hilos::$env[TasksEnvConstants::OAUTH_REDIRECT_URI]->string();

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        return new GenericOAuthProvider($preset->config($clientId, $clientSecret, $redirectUri));
    }
}
