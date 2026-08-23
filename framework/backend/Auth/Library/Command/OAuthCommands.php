<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\DTO\LinkOAuthAfterReauthActionDTO;
use Hilos\Auth\Library\DTO\OAuthCallbackActionDTO;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\Library\DTO\OAuthStartActionDTO;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthAuthorizeSignalData;
use Hilos\Auth\OAuth\DTO\OAuthPendingLoginSignalData;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\Exception\OAuthStateException;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Utils\Logger;
use Random\RandomException;

/**
 * Signing in through a provider, and the account that ends up behind it (HIL-281, HIL-622).
 *
 * The ceremony is split across two processes and this group holds both ends of it while
 * owning neither round-trip. What is here costs nothing: minting an authorize URL, checking
 * the signed `state` a callback came back with, and - after the network is done - deciding
 * which account the provider's answer belongs to. The exchange itself lives in
 * {@see AbstractOAuthAgent}, because talking to a provider takes seconds and this process
 * answers every sign-in of the deployment.
 *
 * The account decision is deliberately keyed on (provider, subject) and never on the
 * address: a provider that reports an email nobody proved to it would otherwise be a way
 * into somebody else's account. The address is consulted for exactly one thing, and it
 * stops the login rather than completing it ({@see completeLogin()}).
 */
final class OAuthCommands extends AbstractLibraryCommands
{
    /**
     * Begins an OAuth login: mints the authorize URL and hands it to the browser.
     *
     * The redirect-start entry (HIL-281). It does no outbound HTTP — it builds the
     * provider authorize URL and a signed, session-bound `state` synchronously — but
     * the framework `action_success` cannot carry a domain payload, so the URL is
     * delivered on the OAUTH_AUTHORIZE signal (WS_USER) to the initiating connection;
     * the SPA navigates there. An unknown provider is a synchronous rejection.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param OAuthStartActionDTO $dto Parsed start payload (provider)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the project has no OAuth wiring, or the provider is not configured
     * @throws InvalidArgumentException When the authorize signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a state nonce
     * @throws HilosException When the provider registry cannot be built
     */
    public function startOAuth(string $acceptKey, OAuthStartActionDTO $dto): void
    {
        $acting = $this->acting($acceptKey);

        try {
            $authorizeUrl = $this->oauthService()->beginAuthorization($dto->provider, $acting->sessionToken);
        } catch (OAuthUnknownProviderException) {
            throw new ValidationException(AuthMessages::UNKNOWN_PROVIDER);
        }

        $this->library->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_AUTHORIZE,
            $acting->acceptKey,
            new OAuthAuthorizeSignalData($acting->acceptKey, $authorizeUrl),
        );
    }

    /**
     * Accepts an OAuth callback: verifies the state and records the exchange op.
     *
     * The callback arm of mechanism B (HIL-281). Provider resolution and state
     * verification are I/O-free, so an unknown provider or a bad/expired/foreign
     * state fails synchronously (CSRF guard) before anything is recorded. On a valid
     * state it records a short-lived in-flight op keyed by the initiating accept key
     * for the framework OAuth agent to exchange across ticks, then returns: the
     * auto-sent `action_success` means only "accepted, working", not "logged in" —
     * the FE keeps its spinner and resolves on the currentUser update (success) or
     * the OAuthResult failure signal.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param OAuthCallbackActionDTO $dto Parsed callback payload (provider, code, state)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws ValidationException When the project has no OAuth wiring, the provider is unknown, or the state is invalid
     * @throws InvalidArgumentException When the hand-off to the OAuth agent cannot be named or queued
     * @throws HilosException When the provider registry cannot be built
     */
    public function callbackOAuth(string $acceptKey, OAuthCallbackActionDTO $dto): void
    {
        $acting = $this->acting($acceptKey);

        $service = $this->oauthService();
        try {
            $service->providerFor($dto->provider);
            $mode = $service->verifyState($dto->state, $acting->sessionToken);
        } catch (OAuthUnknownProviderException | OAuthStateException) {
            throw new ValidationException(AuthMessages::OAUTH_VERIFICATION_FAILED);
        }

        // The linked-to user is taken server-side from the live session, never from
        // the client: a link-mode callback can only ever bind into its own account.
        // A link state on an anonymous session is impossible (the start action is on
        // the authenticated profile page) and is refused as a verification failure.
        $linkUserId = 0;
        if ($mode === OAuthStateSigner::MODE_LINK) {
            if ($acting->userId === null) {
                throw new ValidationException(AuthMessages::OAUTH_VERIFICATION_FAILED);
            }
            $linkUserId = $acting->userId;
        }

        // Hand the verified op to the monopolistic OAuth agent point-to-point; the op has
        // exactly one consumer, so a synced agent signal — not a cross-process runtime
        // collection — is what carries it across the process boundary (HIL-281).
        $this->library->sendToAgent(
            HilosSignalConstants::HILOS_OAUTH_PENDING,
            new OAuthPendingLoginSignalData(
                $acting->acceptKey,
                $acting->sessionToken,
                $dto->provider,
                $dto->code,
                microtime(true) * TimeConstants::MS_PER_SECOND + OAuthPendingLogin::EXCHANGE_TTL_MS,
                $mode,
                $linkUserId,
            ),
        );
    }

    /**
     * Links an OAuth account to the re-authenticated user after an email collision (HIL-282).
     *
     * The redemption arm of the collision flow. Authenticated (see AUTH_ACTIONS):
     * the signed link token minted by the collision branch is verified for HMAC and
     * expiry, then its email must resolve to the now-signed-in user — this is the
     * ownership proof that a full re-authentication provided, so a token minted for
     * one account can never link into another. The verified oauth identity is then
     * bound. A bad, expired, foreign-owned, or already-linked token all fail with
     * the same generic message; nothing about the matched account crosses the wire.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param LinkOAuthAfterReauthActionDTO $dto Parsed link payload (signed token)
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the project has no OAuth wiring, or the token is invalid, foreign-owned, or already linked
     * @throws HilosException When the identity lookup or bind fails
     */
    public function linkAfterReauth(string $acceptKey, LinkOAuthAfterReauthActionDTO $dto): void
    {
        $acting = $this->actingUser($acceptKey);

        $link = $this->oauthService()->verifyLinkToken($dto->token);
        if ($link === null) {
            throw new ValidationException(AuthMessages::INVALID_LINK);
        }

        if (Hilos::$db->identities->findUserIdByVerifiedEmail($link->email) !== $acting->userId) {
            throw new ValidationException(AuthMessages::INVALID_LINK);
        }

        try {
            Hilos::$db->identities->createOauthIdentity($acting->userId, $link->provider, $link->subject);
        } catch (DuplicateValueException) {
            throw new ValidationException(AuthMessages::INVALID_LINK);
        }
    }

    /**
     * Resolves, links, or creates the account the finished exchange belongs to (HIL-281, HIL-282).
     *
     * The half of an OAuth login that touches the user set, and therefore the half that
     * had to leave the OAuth agent: resolution keys on (provider, subject), so an existing
     * oauth identity simply names its user. Otherwise, before a first-login create, the
     * address the provider reported is checked against existing verified identities — a
     * match pauses for re-authentication instead of signing anybody in, so a shared email
     * cannot silently seize an account.
     *
     * No match mints the account: a user under the name the exchange settled on, a verified
     * oauth identity, whatever the project writes about a new member. When the provider
     * reported an address it is ALSO kept as a verified magic-link identity (HIL-405) so the
     * proven address resolves for the profile add-password flow; that write soft-degrades on
     * a check-vs-insert race, because a completed sign-in must not abort over bookkeeping.
     * A provider that withholds the address (HIL-573) skips both the collision check and
     * that write.
     *
     * @param OAuthLoginReadySignalData $data Provider facts the exchange settled on
     * @throws EmptyValueException When the user create refuses an empty display name
     * @throws InvalidArgumentException When a frame this completion sends cannot be named or queued
     * @throws ValidationException When the project has no OAuth wiring to mint a link token with
     * @throws HilosException When the identity lookup, the account, or the project's bookkeeping fails
     */
    public function completeLogin(OAuthLoginReadySignalData $data): void
    {
        $acting = new ActingSession($data->acceptKey, $data->sessionToken, null);

        $identity = Hilos::$db->identities->findByIdentity(
            IdentityType::OAUTH,
            $data->provider . ':' . $data->subject,
        );
        if ($identity !== null && $identity->userId !== null) {
            $this->library->grantSession($acting, $identity->userId);

            return;
        }

        $email = $data->email;
        if ($email !== null && Hilos::$db->identities->findUserIdByVerifiedEmail($email) !== null) {
            $this->requireReauthToLink($data, $email);

            return;
        }

        $userId = $this->library->createUser($data->displayName);
        Hilos::$db->identities->createOauthIdentity($userId, $data->provider, $data->subject);
        if ($email !== null) {
            try {
                Hilos::$db->identities->createMagicLinkIdentity($userId, $email);
            } catch (DuplicateValueException $e) {
                Logger::logAgentWarning(
                    $this->library->getId(),
                    "OAuth email identity skipped for {$data->acceptKey} (race on {$email}): " . $e->getMessage(),
                );
            }
        }
        $this->library->afterUserCreated($userId, $data->provider . ':' . $data->subject);
        $this->library->grantSession($acting, $userId);
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
     * @param OAuthLoginReadySignalData $data Provider facts the exchange settled on
     * @param string $email Colliding address the caller already read off the identity
     * @throws ValidationException When the project has no OAuth wiring to mint the token with
     * @throws InvalidArgumentException When the result signal cannot be named or queued
     * @throws HilosException When the provider registry cannot be built
     */
    private function requireReauthToLink(OAuthLoginReadySignalData $data, string $email): void
    {
        $linkToken = $this->oauthService()->issueLinkToken($data->provider, $data->subject, $email);

        $this->library->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_RESULT,
            $data->acceptKey,
            new OAuthResultSignalData(
                $data->acceptKey,
                $data->provider,
                OAuthResultSignalData::REASON_REAUTH_REQUIRED,
                $email,
                $linkToken,
            ),
        );
    }

    /**
     * The project's OAuth wiring, refusing the command when it carries none.
     *
     * The seam is optional because signing in through a provider is: a project that
     * offers passwords and links alone builds no service, and the actions this group
     * owns are then refused rather than silently doing nothing. Reading it here and
     * not at start-up keeps a project without providers from paying for the wiring it
     * does not have.
     *
     * @return OAuthService The service the project's providers are configured on
     * @throws ValidationException When the project declares no OAuth wiring
     * @throws HilosException When the provider registry cannot be built
     */
    private function oauthService(): OAuthService
    {
        return $this->library->oauthService()
            ?? throw new ValidationException(AuthMessages::UNKNOWN_PROVIDER);
    }
}
