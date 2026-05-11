<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosSettingsPage - Abstract base for Hilos settings page.
 *
 * Subscribe behavior is fully driven by the projection layer: projects register
 * a {@see \Hilos\Core\Projection\PageProjection} for {@see HilosPageConstants::HILOS_SETTINGS}
 * and the initial snapshot signal is built and delivered through
 * {@see \Hilos\Core\Page\AbstractPage::onSubscribe()}.
 */
abstract class AbstractHilosSettingsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;
}
