<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosBackupPage - Abstract base for Hilos backup list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Backup\BackupPage).
 */
abstract class AbstractHilosBackupPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BACKUP;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BACKUP,
            $acceptKey,
            new SignalData(),
        );
    }
}
