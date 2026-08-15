<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\OAuthBindSessionSignalData;
use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Hilos;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
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
     * silently seize an account. No match mints a user (display name from the
     * provider, {@see displayNameFor()}), a verified oauth identity, and the
     * "registered in chat" event. When the provider reported an email it is ALSO
     * persisted as a verified magic_link identity (HIL-405) so the proven email
     * resolves for the profile add-password flow; that best-effort write
     * soft-degrades on a check-vs-insert race (a completed sign-in must not abort).
     * A provider that withholds the address hands over null (HIL-573), and both the
     * collision check and that write are skipped. A resolved or created account
     * signals the session-owning ChatAgent to authenticate the live session, which
     * fans the currentUser update (HIL-161).
     *
     * @param OAuthPendingLogin $op In-flight op carrying the initiating session token
     * @param OAuthUserInfo $info Resolved provider subject, and email/name when the provider gave them
     * @throws EmptyValueException When the user create refuses an empty display name
     * @throws InvalidArgumentException When a signal this completion sends carries no name
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

        $email = $info->email;

        $collisionUserId = $email === null
            ? null
            : Hilos::$db->identities->findUserIdByVerifiedEmail($email);
        if ($collisionUserId !== null) {
            $this->requireReauthToLink($op, $info, $email);

            return;
        }

        $user = Hilos::$db->users->actions->createWithName($this->displayNameFor($op, $info));
        $userId = (int)$user->id;
        Hilos::$db->identities->createOauthIdentity($userId, $op->provider, $info->subject);
        if ($email !== null) {
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
            new OAuthBindSessionSignalData($op->sessionToken, $userId, $op->acceptKey),
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
     * @param OAuthUserInfo $info Resolved provider identity
     * @param string $email Colliding address the caller already read off the identity
     */
    private function requireReauthToLink(OAuthPendingLogin $op, OAuthUserInfo $info, string $email): void
    {
        $linkToken = ChatOAuthConfig::buildService()->issueLinkToken($op->provider, $info->subject, $email);

        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_RESULT,
            $op->acceptKey,
            new OAuthResultSignalData(
                $op->acceptKey,
                $op->provider,
                OAuthResultSignalData::REASON_REAUTH_REQUIRED,
                $email,
                $linkToken,
            ),
        );
    }

    /**
     * Names a new account from the provider's display name, or from the identity itself.
     *
     * The provider's name when it arrived and fits the frame the profile rename
     * applies; otherwise the technical `provider:subject` — `oauth:github:1234567`
     * — truncated to that same maximum. The address takes no part in the name
     * (HIL-573) and the answer is never empty, so a provider that hands over
     * nothing but a subject still gets a readable account.
     *
     * @param OAuthPendingLogin $op In-flight op carrying the provider key
     * @param OAuthUserInfo $info Resolved provider identity
     * @return string Display name for the account about to be created
     */
    private function displayNameFor(OAuthPendingLogin $op, OAuthUserInfo $info): string
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
