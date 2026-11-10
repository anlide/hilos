<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos;

use Demo\Pmd\Agents\PmdAgent;
use Demo\Pmd\Auth\PmdOAuthConfig;
use Demo\Pmd\Constants\AgentType;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosProfileSignInPage;

/**
 * Pmd binding of the framework current-user sign-in methods page.
 *
 * The reads are the framework page's own (HIL-1138): the identities the browser list of
 * this page draws are among what its link start reads, so the page declares no list of
 * its own - one here would replace the parent's rather than add to it.
 *
 * @property PmdAgent $agent
 */
final class ProfileSignInPage extends AbstractHilosProfileSignInPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PMD;

    /**
     * Shares the users library's provider wiring, so link start and return use the same signer.
     *
     * @return OAuthService Service over the demo's provider credentials
     * @throws HilosException Whatever reading the OAuth providers' configuration raises
     */
    protected function oauthService(): ?OAuthService
    {
        return PmdOAuthConfig::buildService();
    }
}
