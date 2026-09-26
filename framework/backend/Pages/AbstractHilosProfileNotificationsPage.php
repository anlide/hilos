<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Notification\NotificationChannelPreferenceProjector;

/**
 * The current user's notification channels, served by the project's agent (HIL-493).
 *
 * The first response carries the preferences and channel addresses. Later changes travel on
 * the person's notification group, which the application shell already subscribes to.
 */
abstract class AbstractHilosProfileNotificationsPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE_NOTIFICATIONS;

    public const PageReach REACH = PageReach::ROUTE;

    /** Required here because AbstractPage grants public access by default. */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    /** Page-data section slot carrying the per-user notification preferences (HIL-485). */
    public const string NOTIFICATION_SECTION = 'notificationPreferences';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE_NOTIFICATIONS,
    ];

    public const array READS_DB = [
        HilosDbContext::identities,
        HilosDbContext::notificationPreferences,
        HilosDbContext::pushSubscriptions,
    ];

    /**
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused; the page has none)
     * @return ?PagePayload Notification preferences, or null outside a signed-in session
     * @throws DatabaseException When a preference or address lookup query fails
     * @throws SettingException When a channel's enablement key cannot be read
     * @throws DbCollectionNotReadableException When the preferences collection is not readable here
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return null;
        }

        return new PagePayload(data: [
            self::NOTIFICATION_SECTION => new NotificationChannelPreferenceProjector()->sectionData($userId)->toArray(),
        ]);
    }
}
