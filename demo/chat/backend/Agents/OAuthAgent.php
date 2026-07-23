<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\OAuthBindSessionSignalData;
use Demo\Chat\Hilos;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Runtime\State\Item\OAuthPendingLogin;

/**
 * OAuthAgent - the chat demo's concrete OAuth login agent (HIL-281 / HIL-282).
 *
 * Supplies the two project hooks the framework {@see AbstractOAuthAgent} leaves
 * open: {@see buildProviderRegistry()} from {@see ChatOAuthConfig}, and
 * {@see completeOAuthLogin()} which resolves the account by (oauth, provider:subject),
 * creating the user + oauth identity on first login, then signals the ChatAgent to
 * bind the session.
 *
 * Account resolution keys strictly on (provider, subject); the provider email is
 * consulted only for the HIL-282 collision guard — before a first-login create, a
 * verified email match against an existing identity does NOT silently sign in but
 * pauses for re-authentication ({@see requireReauthToLink()}).
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
     * Resolves, links, or creates the account for a finished exchange (HIL-281 / HIL-282).
     *
     * Resolution keys on (provider, subject): an existing oauth identity binds its
     * user. Otherwise, before a first-login create, the provider email is checked
     * against existing verified identities ({@see requireReauthToLink()}); a match
     * pauses for re-authentication instead of signing in, so a shared email cannot
     * silently seize an account. No match mints a user (display name from the email
     * local part), a verified oauth identity, and the "registered in chat" event.
     * When the provider email is non-empty it is ALSO persisted as a verified
     * magic_link identity (HIL-405) so the proven email resolves for the profile
     * add-password flow; that best-effort write soft-degrades on a check-vs-insert
     * race (a completed sign-in must not abort). The email is lowercased once and
     * reused for the collision check, display name, and this write. A resolved or
     * created account signals the session-owning ChatAgent to authenticate the live
     * session, which fans the currentUser update (HIL-161).
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
            $this->bindSession($op, $identity->userId);

            return;
        }

        $email = mb_strtolower(trim($info->email));

        $collisionUserId = $email === ''
            ? null
            : Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($collisionUserId !== null) {
            $this->requireReauthToLink($op, $info);

            return;
        }

        $user = Hilos::$db->users->actions->createWithName($this->displayNameFromEmail($email));
        $userId = (int)$user->id;
        Hilos::$db->identities->createOauthIdentity($userId, $op->provider, $info->subject);
        if ($email !== '') {
            try {
                Hilos::$db->identities->createMagicLinkIdentity($userId, $email);
            } catch (DuplicateValueException $e) {
                $this->logAgentWarning(
                    "OAuth email identity skipped for {$op->getId()} (race on {$email}): " . $e->getMessage(),
                );
            }
        }
        Hilos::$db->events->actions->addUserRegistered($userId);
        $this->bindSession($op, $userId);
    }

    /**
     * Signals the session-owning ChatAgent to authenticate the live session to a user.
     *
     * @param OAuthPendingLogin $op In-flight op carrying the initiating session token
     * @param int $userId Resolved user id to bind the session to
     */
    private function bindSession(OAuthPendingLogin $op, int $userId): void
    {
        $this->sendToAgent(
            ChatSignalConstants::OAUTH_BIND_SESSION,
            new OAuthBindSessionSignalData($op->sessionToken, $userId),
        );
    }

    /**
     * Pauses a colliding first login for re-authentication instead of signing in (HIL-282).
     *
     * The provider email matches an existing verified identity, so no user is
     * created and nobody is signed in: a stateless link token is minted and the
     * re-auth-required result is delivered to the initiating connection. The
     * surface re-authenticates the owner (email pre-filled) and redeems the token
     * through the link action, which is where the identity is finally bound.
     *
     * @param OAuthPendingLogin $op In-flight op carrying the initiating accept key
     * @param OAuthUserInfo $info Resolved provider subject and colliding email
     */
    private function requireReauthToLink(OAuthPendingLogin $op, OAuthUserInfo $info): void
    {
        $linkToken = ChatOAuthConfig::buildService()->issueLinkToken($op->provider, $info->subject, $info->email);

        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_RESULT,
            $op->acceptKey,
            new OAuthResultSignalData(
                $op->acceptKey,
                $op->provider,
                OAuthResultSignalData::REASON_REAUTH_REQUIRED,
                $info->email,
                $linkToken,
            ),
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
