<?php

declare(strict_types=1);

namespace Hilos\Pages\Sil;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosSilRequestsPage - Abstract base for Hilos SIL requests list.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Sil\SilRequestsPage).
 */
abstract class AbstractHilosSilRequestsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SIL_REQUESTS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SIL_REQUESTS,
    ];
}
