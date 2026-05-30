<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosSecurityTwoFactorPage - Two-factor authentication settings.
 */
abstract class AbstractHilosSecurityTwoFactorPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SECURITY_2FA;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY_2FA,
    ];
}
