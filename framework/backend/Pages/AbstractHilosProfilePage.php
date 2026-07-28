<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Page\AbstractPage;

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
 * The profile is a signed-in-only surface, so the framework guards its
 * subscription with a flagless AUTHENTICATED guard: an anonymous session is
 * denied a 401 and the in-place auth-gate slot mounts sign-in over the page,
 * resuming the moment the session upgrades. The guard needs no project source or
 * flag, so it lives on the base and applies to every Hilos project's profile.
 */
abstract class AbstractHilosProfilePage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE;

    /** Page-data section slot carrying the per-user notification preferences (HIL-485). */
    public const string NOTIFICATION_SECTION = 'notificationPreferences';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE,
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED,
            ],
        ],
    ];
}
