<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos;

use Demo\Pmd\Constants\AgentType;
use Demo\Pmd\Database\PmdDbContext;
use Demo\Pmd\Hilos;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Pages\AbstractHilosProfileDevicesPage;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;

/** PMD binding of the framework current-user push devices page. */
final class ProfileDevicesPage extends AbstractHilosProfileDevicesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PMD;
    public const array READS_DB = [
        ...parent::READS_DB,
        PmdDbContext::identities,
        PmdDbContext::notificationPreferences,
        PmdDbContext::pushSubscriptions,
    ];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     * @return ?PagePayload Notification channels, or null outside a signed-in session
     * @throws DatabaseException When a preference or address lookup query fails
     * @throws SettingException When a channel's enablement key cannot be read
     * @throws DbCollectionNotReadableException When the preferences collection is not readable here
     */
    protected function buildProfilePagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return null;
        }

        return new PagePayload(data: [
            AbstractHilosProfileNotificationsPage::NOTIFICATION_SECTION => new NotificationChannelPreferenceProjector()
                ->sectionData($userId)
                ->toArray(),
        ]);
    }
}
