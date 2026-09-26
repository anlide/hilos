<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Hilos;
use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\HilosException;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Chat demo implementation of the framework current-user profile page.
 *
 * The framework owns the page identity (key, route, subscription signal); this concrete binds
 * the chat agent, the self-connection browser data, and the notification section the
 * subscription carries.
 *
 * It is a READING surface (HIL-771). Every submit that writes a person lives where those tables
 * are owned: the rename on {@see UsersLibraryAgent}, the ways in and the email change on the
 * framework's users library (HIL-1137). The one submit the page hosts, starting a provider link,
 * is the framework base's; the chat hands it the provider wiring through {@see oauthService()},
 * the same service its users library builds. It is still served by the chat agent, because that
 * is the agent its browser data belongs to.
 *
 * The chat's profile is one page, so the account deletion's danger zone is drawn here, under the
 * sections (HIL-302): the page carries the person's deletion state and puts the connection on
 * the person's {@see AccountDeletionGroup}, as the framework's security page does elsewhere.
 *
 * @property ChatAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    /**
     * @var list<string> What is left to read once the writing submits have gone (HIL-771): the
     *     notification section the subscription carries, the two stores a channel resolves
     *     a person's address in, and the account deletion requests the danger zone is (HIL-302).
     */
    public const array READS_DB = [
        ChatDbContext::identities,
        ChatDbContext::notificationPreferences,
        ChatDbContext::pushSubscriptions,
        ChatDbContext::sessions,
        ChatDbContext::accountDeletions,
    ];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    /**
     * Contributes the signed-in user's notification preferences as the profile's
     * page-data section (HIL-485), and the person's account deletion state beside it (HIL-302).
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
     * @return ?PagePayload Notification section and deletion state, or null outside a signed-in session
     * @throws HilosException When a preference, address or deletion lookup fails
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
            AccountDeletionStateProjector::SECTION => AccountDeletionStateProjector::stateFor(
                Hilos::$rt->selfConnection->userId,
            )->toArray(),
        ]);
    }

    /**
     * Puts the connection on the person's account deletion group once the subscription is answered (HIL-302).
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused; profile has none)
     * @throws HilosException When the join announcement cannot be named
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$rt->selfConnection?->userId;
        if ($userId === null) {
            return;
        }

        AccountDeletionGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
    }

    /**
     * Hands the profile's provider-link start the chat's provider wiring (HIL-1137).
     *
     * The same service {@see UsersLibraryAgent} builds: the state signed on the start is
     * verified by the library on the return.
     *
     * @return OAuthService Service over the demo's provider credentials
     * @throws HilosException Whatever reading the OAuth providers' configuration raises
     */
    protected function oauthService(): ?OAuthService
    {
        return ChatOAuthConfig::buildService();
    }
}
