<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\Profile\LinkOAuthStartActionDTO;
use Demo\Chat\Pages\MainPage;
use Hilos\Auth\OAuth\DTO\OAuthAuthorizeSignalData;
use Hilos\Auth\OAuth\Exception\OAuthUnknownProviderException;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\DatabaseException;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Pages\AbstractHilosProfilePage;
use Random\RandomException;

/**
 * Chat demo implementation of the framework current-user profile page.
 *
 * The framework owns the page identity (key, route, subscription signal); this concrete binds
 * the chat agent, the self-connection browser data, and the notification section the
 * subscription carries.
 *
 * It is a READING surface now (HIL-771). Every submit that wrote a person - the rename with
 * its moderation round trip, the unlink, the password, the phone and email adds - moved to
 * {@see UsersLibraryAgent}, which owns those tables; the page kept only the OAuth link start,
 * which mints a URL and writes nothing. It is still served by the chat agent, because that is
 * the agent its browser data belongs to.
 *
 * @property ChatAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    /**
     * @var list<string> What is left to read once the writing submits have gone (HIL-771): the
     *     notification section the subscription carries, and the two stores a channel resolves
     *     a person's address in.
     */
    public const array READS_DB = [
        ChatDbContext::identities,
        ChatDbContext::notificationPreferences,
        ChatDbContext::pushSubscriptions,
    ];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    /**
     * The one submit left here: it writes nothing, so it needs no owner (HIL-771).
     *
     * Everything else the profile offered - the rename and its moderation round trip, the
     * unlink, the password, the phone and email adds - writes a person, and lives on
     * {@see UsersLibraryAgent} where that table is owned. The wire names did not change, so
     * the frontend is unaware the page stopped hosting them.
     */
    public const array ACTIONS = [
        HilosSignalConstants::HILOS_LINK_OAUTH_START => LinkOAuthStartActionDTO::class,
    ];

    /**
     * Routes the one profile action left on the page to its handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ValidationException When an OAuth link provider is unknown
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws InvalidArgumentException When the authorize-URL signal cannot be named or queued
     * @throws RandomException When minting an OAuth link state cannot draw from the CSPRNG
     * @return ?ActionReplyDTO Always null: the link start answers with a signal, not a reply
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        if ($action !== HilosSignalConstants::HILOS_LINK_OAUTH_START) {
            throw new AgentUnknownActionException("Unknown action: {$action}");
        }
        if (!$dto instanceof LinkOAuthStartActionDTO) {
            throw new InvalidActionPayloadException($action, LinkOAuthStartActionDTO::class, $dto);
        }

        $this->handleLinkOAuthStart($dto);

        return null;
    }

    /**
     * Contributes the signed-in user's notification preferences as the profile's
     * page-data section (HIL-485).
     *
     * The profile is a self-only surface with no route params: the recipient is the
     * session user, read from the self-connection (never a client value), so an
     * anonymous or session-less subscribe contributes no section and leaves the
     * browser identities snapshot to run alone. The section is a computed projection
     * ({@see NotificationChannelPreferenceProjector::sectionData()}), not a browser
     * snapshot, so it rides the one-shot subscription payload rather than the
     * reactive browser data.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection (unused; this page reads its subscriber off the self-connection)
     * @param PageRouteParams $params Route params for the profile subscription (unused; profile has none)
     * @return ?PagePayload Notification-section payload, or null outside a signed-in session
     * @throws DatabaseException When a preference or address lookup query fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            return null;
        }

        return new PagePayload(data: [
            self::NOTIFICATION_SECTION => new NotificationChannelPreferenceProjector()
                ->sectionData(Hilos::$rt->selfConnection->userId)
                ->toArray(),
        ]);
    }

    /**
     * Begins linking an OAuth provider to the signed-in account (HIL-401).
     *
     * The link-mode analog of the login start action: authenticated (the whole
     * profile page is an AUTHENTICATED surface), it mints a link-mode authorize URL
     * whose signed `state` carries mode=link so the callback binds the identity to
     * this session's user instead of resolving an account. The initiator's user id
     * is not carried here — it is read server-side from the session at callback time
     * ({@see MainPage::handleOauthCallback()}), so a client can
     * never link into another account. The URL rides the OAUTH_AUTHORIZE signal
     * (the framework `action_success` carries no domain payload); the SPA navigates
     * there. An unknown provider is a synchronous rejection.
     *
     * @param LinkOAuthStartActionDTO $dto Parsed link-start payload (provider, trip id)
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the provider is not configured
     * @throws InvalidArgumentException When the authorize-URL signal cannot be named or queued
     * @throws RandomException When the platform CSPRNG cannot produce a state nonce
     */
    private function handleLinkOAuthStart(LinkOAuthStartActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            $this->logAgentError('User not found for OAuth link start');
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        $connection = Hilos::$rt->selfConnection;

        try {
            $authorizeUrl = ChatOAuthConfig::buildService()
                ->beginAuthorization($dto->provider, $connection->sessionToken, OAuthStateSigner::MODE_LINK);
        } catch (OAuthUnknownProviderException) {
            throw new ValidationException('Unknown authentication provider');
        }

        $this->sendToUser(
            HilosSignalConstants::HILOS_OAUTH_AUTHORIZE,
            $connection->acceptKey,
            new OAuthAuthorizeSignalData($connection->acceptKey, $authorizeUrl, $dto->tripId, $dto->provider),
        );
    }
}
