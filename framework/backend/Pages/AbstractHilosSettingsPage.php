<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Projection\PageProjection;

/**
 * Base class for the framework Hilos settings page.
 *
 * Subscribe behavior is fully driven by the projection layer: projects register
 * a {@see PageProjection} for {@see HilosPageConstants::HILOS_SETTINGS} and
 * the initial snapshot signal is built by {@see AbstractPage::onSubscribe()}.
 */
abstract class AbstractHilosSettingsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;
}
