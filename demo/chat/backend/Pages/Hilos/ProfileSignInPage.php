<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Database\ChatDbContext;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosProfileSignInPage;

/**
 * Chat binding of the framework current-user sign-in methods page.
 *
 * @property ChatAgent $agent
 */
final class ProfileSignInPage extends AbstractHilosProfileSignInPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array READS_DB = [ChatDbContext::identities];

    /**
     * Shares the users library's provider wiring, so link start and return use the same signer.
     *
     * @return OAuthService Service over the demo's provider credentials
     * @throws HilosException Whatever reading the OAuth providers' configuration raises
     */
    protected function oauthService(): ?OAuthService
    {
        return ChatOAuthConfig::buildService();
    }
}
