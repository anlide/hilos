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
 * The page declares no BROWSER data source and sends no page payload, so a
 * subscribe to it is valid yet answered with nothing. It is no longer
 * content-only, though: what it shows is rendered client-side by the framework's
 * About page - the project's prose and, at the end of it, the block offering to
 * support the project. That block asks the backend for nothing, deliberately;
 * its refusal is a sentence the surface itself carries, so that the payment door
 * behind it inherits no protocol from this page. Projects implement a concrete
 * class (e.g. Demo\Chat\Pages\Hilos\AboutPage) binding the owning agent type.
 */
abstract class AbstractHilosAboutPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_ABOUT;

    public const PageReach REACH = PageReach::ROUTE;

    /** Public footer page: readable without a session ({@see PageAccessLevel}). */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;
}
