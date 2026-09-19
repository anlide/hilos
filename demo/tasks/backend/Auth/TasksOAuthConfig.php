<?php

declare(strict_types=1);

namespace Demo\Tasks\Auth;

use Demo\Tasks\Agents\OAuthAgent;
use Demo\Tasks\Constants\TasksEnvConstants;
use Demo\Tasks\Hilos;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\HilosException;

/**
 * TasksOAuthConfig - the tasks demo's OAuth provider wiring (HIL-623).
 *
 * The single place that builds the framework OAuth objects both halves of the flow need:
 * the {@see OAuthService} the users library runs its
 * provider commands on, and the {@see OAuthProviderRegistry} the async
 * {@see OAuthAgent} drives. Which providers exist is {@see TasksOAuthProviderDirectory}'s;
 * what each one signs in with is resolved by {@see OAuthConfigResolver} - what an
 * administrator entered in the admin, then env, then the preset (HIL-286). A provider
 * whose client id or secret resolves empty is left out - no icon, no sign-in - on every
 * node alike (HIL-924). The test stand fills a fake pair and points the presets at its
 * own provider emulator, so the e2e signs in through a real redirect and exchange.
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
     * Builds the synchronous OAuth service the provider commands run on.
     *
     * The state signer and the account-link signer (HIL-282) share the one OAuth app
     * secret; {@see OAuthLinkTokenSigner} keeps them cryptographically distinct with its
     * domain tag, so no separate secret is provisioned.
     *
     * @return OAuthService Service over the configured providers, state signer, and link signer
     * @throws HilosException Whatever reading the providers' configuration raises
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
     * The providers are TasksOAuthProviderDirectory's, in its order; each one's pair is what an
     * administrator entered, then env (HIL-286), and a provider whose pair resolves empty is
     * left out.
     *
     * @return OAuthProviderRegistry Providers keyed by provider key, in enabled order
     * @throws HilosException Whatever reading the providers' configuration raises
     */
    public static function buildProviderRegistry(): OAuthProviderRegistry
    {
        return new OAuthConfigResolver()->registry();
    }
}
