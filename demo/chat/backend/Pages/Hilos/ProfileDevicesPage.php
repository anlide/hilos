<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\DatabaseException;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Pages\AbstractHilosProfileDevicesPage;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Chat binding of the framework current-user push devices page.
 *
 * @property ChatAgent $agent
 */
final class ProfileDevicesPage extends AbstractHilosProfileDevicesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;
    public const array READS_DB = [
        ChatDbContext::identities,
        ChatDbContext::notificationPreferences,
        ChatDbContext::pushSubscriptions,
    ];

    /**
     * Contributes the notification channel data the device toggle needs on first render.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params for the devices subscription (unused; devices has none)
     * @return ?PagePayload Notification-section payload, or null outside a signed-in session
     * @throws DatabaseException When a preference or address lookup query fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            return null;
        }

        return new PagePayload(data: [
            AbstractHilosProfilePage::NOTIFICATION_SECTION => new NotificationChannelPreferenceProjector()
                ->sectionData(Hilos::$rt->selfConnection->userId)
                ->toArray(),
        ]);
    }
}
