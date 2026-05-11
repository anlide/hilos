<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Projection\PageProjection;

/**
 * Base class for the framework Hilos users list page.
 *
 * Subscribe behavior is fully driven by the projection layer: projects
 * register a {@see PageProjection} for {@see HilosPageConstants::HILOS_USERS}
 * and the initial snapshot signal is built and delivered through
 * {@see AbstractPage::onSubscribe()}.
 */
abstract class AbstractHilosUsersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USERS;
}
