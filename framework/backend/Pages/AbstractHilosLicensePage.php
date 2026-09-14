<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosLicensePage - Abstract base for the Hilos public License page.
 *
 * The page declares no BROWSER data source and sends no page payload, so a
 * subscribe to it is valid yet answered with nothing. Projects implement a
 * concrete class (e.g. Demo\Chat\Pages\Hilos\LicensePage) binding the owning
 * agent type.
 */
abstract class AbstractHilosLicensePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LICENSE;

    public const PageReach REACH = PageReach::ROUTE;

    /** Public footer page: readable without a session ({@see PageAccessLevel}). */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;
}
