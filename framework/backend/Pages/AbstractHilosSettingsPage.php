<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * Base class for the framework Hilos settings page.
 *
 * Subscribe behavior is supplied by project browser configs or page overrides.
 * The base page key is shared with the browser context so projects can return
 * page-shaped settings snapshots through the default subscription handler.
 */
abstract class AbstractHilosSettingsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;
}
