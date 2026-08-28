<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents;

use Demo\Tasks\Auth\TasksOAuthConfig;
use Demo\Tasks\Database\Actions\Item\UserActions;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Runtime\State\Item\OAuthPendingLogin;

/**
 * OAuthAgent - the tasks demo's concrete OAuth login agent (HIL-623).
 *
 * What it carries is provider wiring and a name: resolving the account a finished exchange
 * belongs to - and refusing to seize one on a shared address - is the users library's
 * command, reached by the hand-off frame the framework agent sends.
 *
 * {@see buildProviderRegistry()} names the providers from {@see TasksOAuthConfig}.
 * {@see displayNameFor()} is overridden for one reason only: an account's name has to fit
 * the frame the rename applies, and that length is this project's table's, not the
 * framework's.
 */
final class OAuthAgent extends AbstractOAuthAgent
{
    /**
     * Builds this demo's provider registry (real provider when configured, offline stub otherwise).
     *
     * @return OAuthProviderRegistry Configured providers
     */
    protected function buildProviderRegistry(): OAuthProviderRegistry
    {
        return TasksOAuthConfig::buildProviderRegistry();
    }

    /**
     * Names a new account from the provider's display name, or from the identity itself.
     *
     * The provider's name when it arrived and fits the frame the rename applies; otherwise
     * the technical `provider:subject` - `oauth:github:1234567` - truncated to that same
     * maximum. The address takes no part in the name (HIL-573) and the answer is never
     * empty, so a provider that hands over nothing but a subject still gets a readable
     * account.
     *
     * @param OAuthPendingLogin $op In-flight op carrying the provider key
     * @param OAuthUserInfo $info Resolved provider identity
     * @return string Display name for the account about to be created
     */
    protected function displayNameFor(OAuthPendingLogin $op, OAuthUserInfo $info): string
    {
        $name = $info->name;
        if ($name !== null
            && mb_strlen($name) >= UserActions::NAME_MIN_LENGTH
            && mb_strlen($name) <= UserActions::NAME_MAX_LENGTH
        ) {
            return $name;
        }

        return mb_substr($op->provider . ':' . $info->subject, 0, UserActions::NAME_MAX_LENGTH);
    }
}
