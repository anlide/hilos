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
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogResolver;
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

    /**
     * Page-data slot naming the profile section where the account adds an address - its catalog
     * card - or null when the project does not serve such a section (HIL-1166).
     */
    public const string ADDRESS_SECTION = 'addressSection';

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
            self::ADDRESS_SECTION => self::addressSectionCard(),
        ]);
    }

    /**
     * Returns the catalog card of the section where an address is added, the way the dashboard
     * draws its cards: only when the project serves that page.
     *
     * The browser cannot tell which pages a project serves, so a channel left without an address
     * learns from this card whether its hint may link to the section or stays plain text.
     *
     * @return ?array<string, string> Card of the sign-in section, or null when the project does not serve it
     */
    private static function addressSectionCard(): ?array
    {
        $page = HilosPageConstants::HILOS_PROFILE_SIGN_IN;
        $identity = PageCatalogResolver::identity($page);
        if ($identity === null) {
            return null;
        }

        $cards = static::servedPageCards(
            [[PageCatalogConstants::WIRE_CHILD_PAGE => $page] + $identity],
            PageCatalogConstants::WIRE_CHILD_PAGE,
        );

        return $cards[0] ?? null;
    }
}
