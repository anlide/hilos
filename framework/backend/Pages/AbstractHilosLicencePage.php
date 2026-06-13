<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosLicencePage - Abstract base for the Hilos public Licence page.
 *
 * A static, content-only framework page: it declares no BROWSER data source and
 * sends no page payload (the visible content is rendered client-side), so a
 * subscribe to it is valid yet answered with nothing. Projects implement a
 * concrete class (e.g. Demo\Chat\Pages\Hilos\LicencePage) binding the owning
 * agent type.
 */
abstract class AbstractHilosLicencePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LICENCE;
}
