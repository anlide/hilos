<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\OAuthBindSessionSignalData;
use Demo\Chat\Hilos;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Database\Identity\IdentityType;
use Hilos\Runtime\State\Item\OAuthPendingLogin;

/**
 * OAuthAgent - the chat demo's concrete OAuth login agent (HIL-281).
 *
 * Supplies the two project hooks the framework {@see AbstractOAuthAgent} leaves
 * open: {@see buildProviderRegistry()} from {@see ChatOAuthConfig}, and
 * {@see completeOAuthLogin()} which resolves the account by (oauth, provider:subject),
 * creating the user + oauth identity on first login, then signals the ChatAgent to
 * bind the session. Email is never consulted for resolution here — the
 * email-collision / account-merge branch is deferred to HIL-282.
 */
final class OAuthAgent extends AbstractOAuthAgent
{
    /**
     * Builds the chat provider registry (real provider when configured, offline stub otherwise).
     *
     * @return OAuthProviderRegistry Configured providers
     */
    protected function buildProviderRegistry(): OAuthProviderRegistry
    {
        return ChatOAuthConfig::buildProviderRegistry();
    }

    /**
     * Resolves or creates the account for a finished exchange and binds the session.
     *
     * Resolution is (provider, subject)-only: an existing oauth identity resolves its
     * user; a new subject mints a user (display name from the email local part), a
     * verified oauth identity, and the "registered in chat" event. Either way the
     * session-owning ChatAgent is signalled to authenticate the live session, which
     * fans the currentUser update (HIL-161).
     *
     * @param OAuthPendingLogin $op In-flight op carrying the initiating session token
     * @param OAuthUserInfo $info Resolved provider subject and email
     */
    protected function completeOAuthLogin(OAuthPendingLogin $op, OAuthUserInfo $info): void
    {
        $identity = Hilos::$db->identities->findByIdentity(
            IdentityType::OAUTH,
            $op->provider . ':' . $info->subject,
        );

        if ($identity !== null && $identity->userId !== null) {
            $userId = $identity->userId;
        } else {
            $user = Hilos::$db->users->actions->createWithName($this->displayNameFromEmail($info->email));
            $userId = (int)$user->id;
            Hilos::$db->identities->createOauthIdentity($userId, $op->provider, $info->subject);
            Hilos::$db->events->actions->addUserRegistered($userId);
        }

        $this->sendToAgent(
            ChatSignalConstants::OAUTH_BIND_SESSION,
            new OAuthBindSessionSignalData($op->sessionToken, $userId),
        );
    }

    /**
     * Derives a display name from an email address (its local part).
     *
     * @param string $email Provider-supplied account email (may be empty)
     * @return string Display name (email local part, or the whole string when no `@`)
     */
    private function displayNameFromEmail(string $email): string
    {
        $atPosition = strpos($email, '@');

        return $atPosition === false ? $email : substr($email, 0, $atPosition);
    }
}
