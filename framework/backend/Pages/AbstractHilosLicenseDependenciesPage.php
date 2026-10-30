<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;

/** Public dependency inventory page; its snapshot is a build input. */
abstract class AbstractHilosLicenseDependenciesPage extends AbstractHilosLicensePage
{
    public const string PAGE = HilosPageConstants::HILOS_LICENSE_DEPENDENCIES;
}
