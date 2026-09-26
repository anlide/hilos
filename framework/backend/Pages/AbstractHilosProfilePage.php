<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;

/**
 * The framework current-user profile root, served by the project's agent.
 *
 * The framework owns the page key, route and subscription signal. The project binds its agent
 * and browser data. Each profile section has its own page (HIL-493); the provider-link start
 * belongs to AbstractHilosProfileSignInPage and account writes belong to the users library.
 *
 * AUTHENTICATED must stay explicit: this page extends AbstractPage directly, bypassing the
 * admin default of AbstractHilosPage, and denies an anonymous session with 401.
 */
abstract class AbstractHilosProfilePage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE;

    public const PageReach REACH = PageReach::ROUTE;

    /** Signed-in-only surface; see the class doc for why this must stay explicit. */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE,
    ];
}
