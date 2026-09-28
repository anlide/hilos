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
use Hilos\Auth\StepUp\StepUpGate;
use Hilos\Auth\StepUp\StepUpOperationKey;
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
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;

/**
 * The current user's ways to sign in, served by the project's agent (HIL-493).
 *
 * The framework owns the page identity and the authenticated access level. The project binds
 * its agent and the browser list of identities. The provider-link start belongs here because
 * it writes nothing: it signs a state and returns a URL. Every submit that writes an account
 * stays with the users library, which owns those tables (HIL-1137).
 *
 * The project's provider wiring comes through oauthService(), shared with its users library
 * so the signer on the start and on the return agree.
 *
 * Starting a link is the first step of the add-a-way-in operation (HIL-1138): the gate is asked
 * here, before the URL is minted, and the users library asks it again on the return.
 */
abstract class AbstractHilosProfileSignInPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE_SIGN_IN;

    public const PageReach REACH = PageReach::ROUTE;

    /** Required here because AbstractPage grants public access by default. */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE_SIGN_IN,
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
     * What the link start reads before it writes nothing (HIL-1138): the gate's session row
     * and confirmation, and the proofs the resolver weighs. A project's page that declares
     * reads of its own keeps these with `[...parent::READS_DB, ...]` - a reading list replaces
     * its parent's rather than adding to it.
     */
    public const array READS_DB = [
        HilosDbContext::sessions,
        HilosDbContext::stepUps,
        HilosDbContext::identities,
        HilosDbContext::secondFactors,
        HilosDbContext::passkeyCredentials,
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
     * @throws ItemNotFoundForUpdateException When the connection has no session or is anonymous
     * @throws ValidationException When the add is not confirmed, the provider is switched off, unknown, or the project wires no providers
     * @throws InvalidArgumentException When the authorize-URL signal cannot be named or queued
     * @throws RandomException When minting the link state cannot draw from the CSPRNG
     * @throws HilosException When the confirmation, the provider registry or the sign-in method setting cannot be read
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
     * The add-a-way-in confirmation is asked first (HIL-1138), so a refusal is answered here and
     * the browser never leaves for the provider.
     *
     * @param string $acceptKey Accept key of the tab that asked
     * @param LinkOAuthStartActionDTO $dto Parsed link-start payload (provider, trip id)
     * @throws ItemNotFoundForUpdateException When the connection has no session or is anonymous
     * @throws ValidationException When the add is not confirmed, the provider is switched off, unknown, or the project wires no providers
     * @throws InvalidArgumentException When the authorize-URL signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a state nonce
     * @throws HilosException When the confirmation, the provider registry or the sign-in method setting cannot be read
     */
    private function handleLinkOAuthStart(string $acceptKey, LinkOAuthStartActionDTO $dto): void
    {
        $connection = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey);
        if ($connection?->sessionToken === null || $connection->userId === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        new StepUpGate()->require($connection->sessionToken, $connection->userId, StepUpOperationKey::ADD_SIGN_IN_METHOD);
        AuthMethodGate::assertProviderOpen($dto->provider);

        $service = $this->oauthService() ?? throw new ValidationException(AuthMessages::UNKNOWN_PROVIDER);
        try {
            $authorizeUrl = $service->beginAuthorization($dto->provider, $connection->sessionToken, OAuthStateSigner::MODE_LINK);
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
