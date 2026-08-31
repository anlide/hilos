<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosAboutPage - Abstract base for the Hilos public About page.
 *
 * A static, content-only framework page: it declares no BROWSER data source and
 * sends no page payload (the visible content is rendered client-side), so a
 * subscribe to it is valid yet answered with nothing. Projects implement a
 * concrete class (e.g. Demo\Chat\Pages\Hilos\AboutPage) binding the owning
 * agent type.
 */
abstract class AbstractHilosAboutPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_ABOUT;

    public const PageReach REACH = PageReach::ROUTE;

    /** Public footer page: readable without a session ({@see PageAccessLevel}). */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;
}
