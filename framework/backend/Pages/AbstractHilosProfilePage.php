<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\LinkOAuthStartActionDTO;
use Hilos\Auth\Method\AuthMethodGate;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthAuthorizeSignalData;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * AbstractHilosProfilePage - Abstract base for the Hilos current-user profile page.
 *
 * The framework owns the profile's identity — its page key, route, and
 * subscription signal — so every Hilos project exposes the same `/profile`
 * entry. Unlike the admin pages it extends AbstractPage directly rather than
 * AbstractHilosPage: the profile is served by the project's own agent (it reads
 * the live connection and may run project-specific edit flows such as a
 * moderated rename), not the framework admin agent. The concrete subclass binds
 * the agent, its browser data, and any actions (e.g. Demo\Chat\Pages\Hilos\ProfilePage).
 *
 * The profile is a signed-in-only surface, so the base declares the
 * AUTHENTICATED access level: an anonymous session is denied a 401 and the
 * in-place auth-gate slot mounts sign-in over the page, resuming the moment the
 * session upgrades. The declaration is MANDATORY here: this page extends
 * AbstractPage directly, bypassing the ADMIN default AbstractHilosPage inherits
 * to its subclasses, so staying silent would open the profile to anonymous
 * sessions.
 *
 * It hosts the start of linking a provider to the account (HIL-1137), and hosts it as
 * a page rather than leaving it to the users library: the button is on this page, and
 * the start writes nothing - it signs a state and hands back a URL - so the rule of
 * docs/agents/architecture/entity-libraries.md, "When A Name May Live On The Library
 * (HIL-824)", keeps the name here. The return from the provider and the link itself stay
 * with the library. Which providers there are is the project's answer, given through
 * {@see oauthService()}.
 */
abstract class AbstractHilosProfilePage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE;

    public const PageReach REACH = PageReach::ROUTE;

    /** Signed-in-only surface; see the class doc for why this must stay explicit. */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    /** Page-data section slot carrying the per-user notification preferences (HIL-485). */
    public const string NOTIFICATION_SECTION = 'notificationPreferences';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE,
    ];

    /**
     * The profile's own submit: starting a provider link, which writes nothing (HIL-1137).
     *
     * Every submit that writes the person - a password, a phone, an unlink, an email change -
     * is the users library's, where the account tables are owned.
     */
    public const array ACTIONS = [
        HilosSignalConstants::HILOS_LINK_OAUTH_START => LinkOAuthStartActionDTO::class,
    ];

    /**
     * Routes the profile's action to its handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the link start answers with a signal, not a reply
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws ItemNotFoundForUpdateException When the connection has no session
     * @throws ValidationException When the provider is switched off, unknown, or the project wires no providers
     * @throws InvalidArgumentException When the authorize-URL signal cannot be named or queued
     * @throws RandomException When minting the link state cannot draw from the CSPRNG
     * @throws HilosException When the provider registry or the sign-in method setting cannot be read
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_LINK_OAUTH_START:
                if (!$dto instanceof LinkOAuthStartActionDTO) {
                    throw new InvalidActionPayloadException($action, LinkOAuthStartActionDTO::class, $dto);
                }
                $this->handleLinkOAuthStart($acceptKey, $dto);

                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Returns the project's OAuth wiring, or null when it links no provider.
     *
     * Default is null: a project that offers no provider has nothing to link, and the start
     * is then refused as an unknown provider. A project that offers them returns the SAME
     * service it hands its users library ({@see AbstractUsersLibraryAgent::buildOAuthService()})
     * and its {@see AbstractOAuthAgent}: the state signed here is verified on the return, and
     * a state signed by one signer and checked by another fails every link.
     *
     * @return ?OAuthService Service the project's providers are configured on, or null when it has none
     * @throws HilosException When the provider registry cannot be built
     */
    protected function oauthService(): ?OAuthService
    {
        return null;
    }

    /**
     * Begins linking an OAuth provider to the signed-in account (HIL-401).
     *
     * The link-mode analog of the login start: authenticated (the whole profile page is an
     * AUTHENTICATED surface), it mints a link-mode authorize URL whose signed `state` carries
     * mode=link so the callback binds the identity to this session's user instead of
     * resolving an account. The initiator's user id is not carried here - it is read from the
     * session at callback time, so a client can never link into another account. A provider
     * an administrator switched off is refused before the URL is minted, as the login start
     * refuses it (HIL-427). The URL rides the OAUTH_AUTHORIZE signal to this tab (an action
     * reply carries no domain payload); the browser navigates there.
     *
     * @param string $acceptKey Accept key of the tab that asked
     * @param LinkOAuthStartActionDTO $dto Parsed link-start payload (provider, trip id)
     * @throws ItemNotFoundForUpdateException When the connection has no session
     * @throws ValidationException When the provider is switched off, unknown, or the project wires no providers
     * @throws InvalidArgumentException When the authorize-URL signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a state nonce
     * @throws HilosException When the provider registry or the sign-in method setting cannot be read
     */
    private function handleLinkOAuthStart(string $acceptKey, LinkOAuthStartActionDTO $dto): void
    {
        $sessionToken = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->sessionToken
            ?? throw new ItemNotFoundForUpdateException('User session not found');
        AuthMethodGate::assertProviderOpen($dto->provider);

        $service = $this->oauthService() ?? throw new ValidationException(AuthMessages::UNKNOWN_PROVIDER);
        try {
            $authorizeUrl = $service->beginAuthorization($dto->provider, $sessionToken, OAuthStateSigner::MODE_LINK);
        } catch (OAuthUnknownProviderException) {
            throw new ValidationException(AuthMessages::UNKNOWN_PROVIDER);
        }

        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_AUTHORIZE,
            $acceptKey,
            new OAuthAuthorizeSignalData($acceptKey, $authorizeUrl, $dto->tripId, $dto->provider),
        );
    }
}
