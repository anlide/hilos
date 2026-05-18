<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Pages\AbstractHilosGuardianPage;

/**
 * GuardianPage - Guardian page implementation for demo.
 */
final class GuardianPage extends AbstractHilosGuardianPage
{
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN,
    ];
}
