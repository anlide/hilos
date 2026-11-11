<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Pages\Hilos;

use Demo\SolarSystemModel\Agents\SolarSystemModelAgent;
use Demo\SolarSystemModel\Auth\SolarSystemModelOAuthConfig;
use Demo\SolarSystemModel\Constants\AgentType;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosProfileSignInPage;

/**
 * SolarSystemModel binding of the framework current-user sign-in methods page.
 *
 * The reads are the framework page's own (HIL-1138): the identities the browser list of
 * this page draws are among what its link start reads, so the page declares no list of
 * its own - one here would replace the parent's rather than add to it.
 *
 * @property SolarSystemModelAgent $agent
 */
final class ProfileSignInPage extends AbstractHilosProfileSignInPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::SOLAR_SYSTEM_MODEL;

    /**
     * Shares the users library's provider wiring, so link start and return use the same signer.
     *
     * @return OAuthService Service over the demo's provider credentials
     * @throws HilosException Whatever reading the OAuth providers' configuration raises
     */
    protected function oauthService(): ?OAuthService
    {
        return SolarSystemModelOAuthConfig::buildService();
    }
}
