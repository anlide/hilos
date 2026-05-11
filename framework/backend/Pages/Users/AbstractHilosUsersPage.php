<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosUsersPage - Abstract base for Hilos users list page.
 *
 * Subscribe behavior is fully driven by the projection layer: projects register
 * a {@see \Hilos\Core\Projection\PageProjection} for {@see HilosPageConstants::HILOS_USERS}
 * and the initial snapshot signal is built and delivered through
 * {@see \Hilos\Core\Page\AbstractPage::onSubscribe()}.
 */
abstract class AbstractHilosUsersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USERS;
}
