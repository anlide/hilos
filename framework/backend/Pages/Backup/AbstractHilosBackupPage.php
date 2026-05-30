<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosBackupPage - Abstract base for Hilos backup list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Backup\BackupPage).
 */
abstract class AbstractHilosBackupPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BACKUP;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BACKUP,
    ];
}
