<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosLicensePage - Abstract base for the Hilos public License page.
 *
 * A static, content-only framework page: it declares no BROWSER data source and
 * sends no page payload (the visible content is rendered client-side), so a
 * subscribe to it is valid yet answered with nothing. Projects implement a
 * concrete class (e.g. Demo\Chat\Pages\Hilos\LicensePage) binding the owning
 * agent type.
 */
abstract class AbstractHilosLicensePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LICENSE;
}
