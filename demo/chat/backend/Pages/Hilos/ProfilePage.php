<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Hilos;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\DatabaseException;
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
        ChatDbContext::sessions,
    ];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

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
